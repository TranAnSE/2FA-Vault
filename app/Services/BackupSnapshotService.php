<?php

namespace App\Services;

use App\Exceptions\SnapshotsUnreadableException;
use App\Models\BackupSnapshot;
use App\Models\Group;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Server-side backup snapshot store.
 *
 * A snapshot is a row-level dump of the user's accounts and groups AS STORED —
 * E2EE secrets remain ciphertext, non-E2EE fields are captured as decoded model
 * attributes (what import uses) — serialized as `snapshot-1` JSON and
 * APP_KEY-encrypted at rest on the dedicated `snapshots` disk. The disk is
 * deliberately separate from `backups`: `backup:cleanup` sweeps that disk
 * hourly with a >=1h retention clamp and would delete snapshots within the
 * hour (CleanupBackupFiles.php).
 *
 * Quota (RT-6): max count + max total bytes, config-driven. All create+evict
 * work happens under a per-user lock; eviction is lane-aware (a pre_restore or
 * scheduled snapshot never evicts a manual one; a manual create may evict
 * anything oldest-first) and honours `protected_ids` so a restore can never
 * evict the snapshot being restored. File writes are not rollback-able — the
 * row is inserted first, the file written second, and orphan reconciliation
 * (`snapshots:prune`) cleans leftovers on the next create.
 */
class BackupSnapshotService
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_PRE_RESTORE = 'pre_restore';
    public const SOURCE_SCHEDULED = 'scheduled';

    /** Payload format marker (see Architecture in plan phase-03). */
    public const PAYLOAD_FORMAT = 'snapshot-1';

    /** Seconds the per-user create lock is held/waited. */
    private const LOCK_SECONDS = 30;

    protected string $disk;

    public function __construct()
    {
        $this->disk = config('2fauth.config.snapshotDisk', 'snapshots');
    }

    /**
     * Create a snapshot for the user and enforce the quota. Returns the fresh
     * snapshot model.
     *
     * @param  array<int>  $protectedIds  Snapshot ids that must never be evicted.
     */
    public function createSnapshot(User $user, string $source, ?string $label = null, array $protectedIds = []): BackupSnapshot
    {
        $lock = Cache::lock('snapshot-create:' . $user->id, self::LOCK_SECONDS);

        try {
            // Serialize concurrent creates for the user: the file+row pair and
            // the eviction decision must stay consistent (RT-6).
            $lock->block(self::LOCK_SECONDS);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            Log::warning(sprintf('Snapshot create lock timeout for user ID #%s', $user->id));
            throw new \RuntimeException('Another snapshot operation is in progress. Try again shortly.');
        }

        try {
            return $this->doCreate($user, $source, $label, $protectedIds);
        } finally {
            $lock->release();
        }
    }

    private function doCreate(User $user, string $source, ?string $label, array $protectedIds): BackupSnapshot
    {
        $payload = $this->buildPayload($user, $source);
        $plaintext = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encrypted = Crypt::encryptString($plaintext);
        $checksum = hash('sha256', $encrypted);
        $size = strlen($encrypted);

        // 1. Insert the row first (RT-6): if the file write fails we roll back
        // our own row and rethrow — the file layer is not transactional, the
        // DB layer is.
        $snapshot = BackupSnapshot::create([
            'user_id' => $user->id,
            'source' => $source,
            'label' => $label,
            'accounts_count' => count($payload['accounts']),
            'groups_count' => count($payload['groups']),
            'size_bytes' => $size,
            'checksum' => $checksum,
            'encryption_salt_at_snapshot' => $payload['user']['encryption_salt'],
            'encryption_version_at_snapshot' => $payload['user']['encryption_version'],
            'app_key_fingerprint' => $this->appKeyFingerprint(),
            'file_path' => $this->pathFor($user->id),
        ]);

        try {
            Storage::disk($this->disk)->put($snapshot->file_path, $encrypted);
        } catch (\Throwable $e) {
            $snapshot->delete();
            Log::error(sprintf('Snapshot file write failed for user ID #%s: %s', $user->id, $e->getMessage()));
            throw $e;
        }

        // Eviction must never touch the snapshot being created (nor, of
        // course, the caller's protected ids — e.g. a restore target).
        $this->enforceQuota($user, $source, array_merge($protectedIds, [$snapshot->id]));

        return $snapshot;
    }

    /** Delete a snapshot: file first, then the row. Owner-scoped by callers. */
    public function deleteSnapshot(BackupSnapshot $snapshot): void
    {
        Storage::disk($this->disk)->delete($snapshot->file_path);
        $snapshot->delete();
    }

    /**
     * Load and decode the snapshot payload, verifying the APP_KEY fingerprint
     * and the file checksum. Throws SnapshotsUnreadableException on a rotated
     * APP_KEY (clean 422 surface, never a raw DecryptException) or a corrupted
     * file.
     *
     * @return array<string, mixed>
     */
    public function loadSnapshotPayload(BackupSnapshot $snapshot): array
    {
        if ($snapshot->app_key_fingerprint !== $this->appKeyFingerprint()) {
            throw new SnapshotsUnreadableException('app_key_rotated');
        }

        $encrypted = Storage::disk($this->disk)->get($snapshot->file_path);
        if ($encrypted === null) {
            throw new SnapshotsUnreadableException('file_missing');
        }

        if (! hash_equals($snapshot->checksum, hash('sha256', $encrypted))) {
            throw new SnapshotsUnreadableException('checksum_mismatch');
        }

        $payload = json_decode(Crypt::decryptString($encrypted), true);
        if (! is_array($payload) || ($payload['format'] ?? null) !== self::PAYLOAD_FORMAT) {
            throw new SnapshotsUnreadableException('invalid_payload');
        }

        return $payload;
    }

    /**
     * Why a snapshot cannot be read, or null when it can. Used by the list
     * resource to surface `unreadable_reason` without loading files.
     */
    public function unreadableReason(BackupSnapshot $snapshot): ?string
    {
        return $snapshot->app_key_fingerprint === $this->appKeyFingerprint()
            ? null
            : 'app_key_rotated';
    }

    /** Whether the vault salt changed since the snapshot was taken. */
    public function saltChangedSince(BackupSnapshot $snapshot, User $user): bool
    {
        return (string) $snapshot->encryption_salt_at_snapshot !== (string) $user->encryption_salt;
    }

    /** Short sha256 prefix of APP_KEY — stable while the key is stable. */
    public function appKeyFingerprint(): string
    {
        return substr(hash('sha256', (string) config('app.key')), 0, 16);
    }

    /**
     * Delete orphaned artifacts: encrypted files without a row (rollback
     * leftovers) and rows whose file disappeared. Run on create and
     * schedulable via `snapshots:prune`.
     *
     * @return array{files_removed: int, rows_removed: int}
     */
    public function pruneOrphans(): array
    {
        $disk = Storage::disk($this->disk);
        $knownPaths = BackupSnapshot::query()->pluck('file_path')->all();
        $known = array_flip($knownPaths);
        $filesRemoved = 0;

        foreach ($disk->allFiles() as $file) {
            if (! isset($known[$file])) {
                $disk->delete($file);
                $filesRemoved++;
            }
        }

        // Rows whose file vanished (e.g. manual disk cleanup) are dead entries.
        $rowsRemoved = 0;
        foreach ($knownPaths as $path) {
            if (! $disk->exists($path)) {
                BackupSnapshot::where('file_path', $path)->delete();
                $rowsRemoved++;
            }
        }

        if ($filesRemoved + $rowsRemoved > 0) {
            Log::info(sprintf('snapshots:prune removed %d orphan file(s) and %d dead row(s)', $filesRemoved, $rowsRemoved));
        }

        return ['files_removed' => $filesRemoved, 'rows_removed' => $rowsRemoved];
    }

    /**
     * The snapshot payload: full column projection including the fields the
     * import path drops (notes/is_pinned/recovery_codes/order_column/
     * last_used_at) so restore is a true time-machine. Values are DECODED
     * model attributes (E2EE secrets stay ciphertext envelopes) — the payload
     * re-encrypts under APP_KEY at the file layer.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(User $user, string $source): array
    {
        $accounts = TwoFAccount::where('user_id', $user->id)
            ->orderBy('order_column')
            ->get()
            ->map(fn (TwoFAccount $a) => [
                'id' => $a->id,
                'service' => $a->service,
                'account' => $a->account,
                'secret' => $a->secret,
                'encrypted' => (bool) $a->encrypted,
                'algorithm' => $a->algorithm,
                'digits' => (int) $a->digits,
                'period' => $a->period !== null ? (int) $a->period : null,
                'counter' => $a->counter !== null ? (int) $a->counter : null,
                'otp_type' => $a->otp_type,
                'icon' => $a->icon,
                'group_id' => $a->group_id,
                'notes' => $a->notes,
                'is_pinned' => (bool) $a->is_pinned,
                'recovery_codes' => $a->recovery_codes,
                'order_column' => $a->order_column !== null ? (int) $a->order_column : null,
                'last_used_at' => $a->last_used_at?->toIso8601String(),
                'created_at' => $a->created_at?->toIso8601String(),
                'updated_at' => $a->updated_at?->toIso8601String(),
            ])
            ->all();

        $groups = Group::where('user_id', $user->id)->get()
            ->map(fn (Group $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'order_column' => $g->order_column ?? null,
            ])
            ->all();

        return [
            'app' => '2FA-Vault',
            'format' => self::PAYLOAD_FORMAT,
            'created_at' => now()->utc()->toIso8601String(),
            'source' => $source,
            'user' => [
                'id' => $user->id,
                'encryption_salt' => $user->encryption_salt,
                'encryption_version' => $user->encryption_version,
            ],
            'accounts' => $accounts,
            'groups' => $groups,
        ];
    }

    /**
     * Enforce count + byte quotas with lane-aware eviction (RT-6).
     *
     * - Automatic sources (pre_restore, scheduled) can never evict a manual
     *   snapshot — a pre-restore burst must not destroy intentional snapshots.
     * - A manual create may evict anything, oldest first.
     * - pre_restore evicts oldest pre_restore first, then oldest scheduled.
     * - `protectedIds` (e.g. the snapshot being restored) are never evicted.
     */
    private function enforceQuota(User $user, string $source, array $protectedIds): void
    {
        $maxCount = (int) config('2fauth.config.snapshotMaxCount', 10);
        $maxTotalBytes = ((int) config('2fauth.config.snapshotMaxTotalMb', 100)) * 1024 * 1024;
        $protected = array_flip($protectedIds);

        $candidateQuery = fn () => BackupSnapshot::where('user_id', $user->id)
            ->when($source !== self::SOURCE_MANUAL, fn ($q) => $q->whereIn('source', [self::SOURCE_PRE_RESTORE, self::SOURCE_SCHEDULED]))
            ->whereNotIn('id', $protectedIds)
            ->orderBy('created_at');

        $evictOne = function () use ($candidateQuery): ?BackupSnapshot {
            foreach ([self::SOURCE_PRE_RESTORE, self::SOURCE_SCHEDULED, self::SOURCE_MANUAL] as $lane) {
                /** @var BackupSnapshot|null $victim */
                $victim = $candidateQuery()->where('source', $lane)->first();
                if ($victim) {
                    return $victim;
                }
            }

            return null;
        };

        // Count quota.
        while (BackupSnapshot::where('user_id', $user->id)->count() > $maxCount) {
            $victim = $evictOne();
            if (! $victim || isset($protected[$victim->id])) {
                break;
            }
            $this->deleteSnapshot($victim);
        }

        // Total-bytes quota (sum stored per row, no file reads).
        while ((int) BackupSnapshot::where('user_id', $user->id)->sum('size_bytes') > $maxTotalBytes) {
            $victim = $evictOne();
            if (! $victim || isset($protected[$victim->id])) {
                Log::warning(sprintf('Snapshot byte quota exceeded for user ID #%s but no evictable candidate remains', $user->id));
                break;
            }
            $this->deleteSnapshot($victim);
        }
    }

    private function pathFor(int $userId): string
    {
        return sprintf('u%d/%s.json', $userId, (string) Str::ulid());
    }
}
