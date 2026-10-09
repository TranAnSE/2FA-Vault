<?php

namespace App\Services;

use App\Enums\PersonalAction;
use App\Exceptions\RestoreStateChangedException;
use App\Exceptions\RestoreTokenInvalidException;
use App\Exceptions\SnapshotsUnreadableException;
use App\Facades\IconStore;
use App\Models\BackupSnapshot;
use App\Models\Group;
use App\Models\OtpLog;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
    public function createSnapshot(User $user, string $source, ?string $label = null, array $protectedIds = []) : BackupSnapshot
    {
        $lock = Cache::lock('snapshot-create:' . $user->id, self::LOCK_SECONDS);

        try {
            // Serialize concurrent creates for the user: the file+row pair and
            // the eviction decision must stay consistent (RT-6).
            $lock->block(self::LOCK_SECONDS);
        } catch (LockTimeoutException) {
            Log::warning(sprintf('Snapshot create lock timeout for user ID #%s', $user->id));
            throw new \RuntimeException('Another snapshot operation is in progress. Try again shortly.');
        }

        try {
            return $this->doCreate($user, $source, $label, $protectedIds);
        } finally {
            $lock->release();
        }
    }

    private function doCreate(User $user, string $source, ?string $label, array $protectedIds) : BackupSnapshot
    {
        $payload   = $this->buildPayload($user, $source);
        $plaintext = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encrypted = Crypt::encryptString($plaintext);
        $checksum  = hash('sha256', $encrypted);
        $size      = strlen($encrypted);

        // 1. Insert the row first (RT-6): if the file write fails we roll back
        // our own row and rethrow — the file layer is not transactional, the
        // DB layer is.
        $snapshot = BackupSnapshot::create([
            'user_id'                        => $user->id,
            'source'                         => $source,
            'label'                          => $label,
            'accounts_count'                 => count($payload['accounts']),
            'groups_count'                   => count($payload['groups']),
            'size_bytes'                     => $size,
            'checksum'                       => $checksum,
            'encryption_salt_at_snapshot'    => $payload['user']['encryption_salt'],
            'encryption_version_at_snapshot' => $payload['user']['encryption_version'],
            'app_key_fingerprint'            => $this->appKeyFingerprint(),
            'file_path'                      => $this->pathFor($user->id),
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
    public function deleteSnapshot(BackupSnapshot $snapshot) : void
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
    public function loadSnapshotPayload(BackupSnapshot $snapshot) : array
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
    public function unreadableReason(BackupSnapshot $snapshot) : ?string
    {
        return $snapshot->app_key_fingerprint === $this->appKeyFingerprint()
            ? null
            : 'app_key_rotated';
    }

    /** Whether the vault salt changed since the snapshot was taken. */
    public function saltChangedSince(BackupSnapshot $snapshot, User $user) : bool
    {
        return (string) $snapshot->encryption_salt_at_snapshot !== (string) $user->encryption_salt;
    }

    /** Short sha256 prefix of APP_KEY — stable while the key is stable. */
    public function appKeyFingerprint() : string
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
    public function pruneOrphans() : array
    {
        $disk         = Storage::disk($this->disk);
        $knownPaths   = BackupSnapshot::query()->pluck('file_path')->all();
        $known        = array_flip($knownPaths);
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
                // Grace window: a row created seconds ago may predate its file
                // write landing (row first, stream after) — never judge a
                // fresh row dead while a create is still in flight.
                BackupSnapshot::where('file_path', $path)
                    ->where('created_at', '<', now()->subMinutes(5))
                    ->delete();
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
    public function buildPayload(User $user, string $source) : array
    {
        $accounts = TwoFAccount::where('user_id', $user->id)
            ->orderBy('order_column')
            ->get()
            ->map(fn (TwoFAccount $a) => [
                'id'             => $a->id,
                'service'        => $a->service,
                'account'        => $a->account,
                'secret'         => $a->secret,
                'encrypted'      => (bool) $a->encrypted,
                'algorithm'      => $a->algorithm,
                'digits'         => (int) $a->digits,
                'period'         => $a->period !== null ? (int) $a->period : null,
                'counter'        => $a->counter !== null ? (int) $a->counter : null,
                'otp_type'       => $a->otp_type,
                'icon'           => $a->icon,
                'group_id'       => $a->group_id,
                'notes'          => $a->notes,
                'is_pinned'      => (bool) $a->is_pinned,
                'recovery_codes' => $a->recovery_codes,
                'order_column'   => $a->order_column !== null ? (int) $a->order_column : null,
                'last_used_at'   => $a->last_used_at?->toIso8601String(),
                'created_at'     => $a->created_at?->toIso8601String(),
                'updated_at'     => $a->updated_at?->toIso8601String(),
            ])
            ->all();

        $groups = Group::where('user_id', $user->id)->get()
            ->map(fn (Group $g) => [
                'id'           => $g->id,
                'name'         => $g->name,
                'order_column' => $g->order_column ?? null,
            ])
            ->all();

        return [
            'app'        => '2FA-Vault',
            'format'     => self::PAYLOAD_FORMAT,
            'created_at' => now()->utc()->toIso8601String(),
            'source'     => $source,
            'user'       => [
                'id'                 => $user->id,
                'encryption_salt'    => $user->encryption_salt,
                'encryption_version' => $user->encryption_version,
            ],
            'accounts' => $accounts,
            'groups'   => $groups,
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
    private function enforceQuota(User $user, string $source, array $protectedIds) : void
    {
        $maxCount      = (int) config('2fauth.config.snapshotMaxCount', 10);
        $maxTotalBytes = ((int) config('2fauth.config.snapshotMaxTotalMb', 100)) * 1024 * 1024;
        $protected     = array_flip($protectedIds);

        $candidateQuery = fn () => BackupSnapshot::where('user_id', $user->id)
            ->when($source !== self::SOURCE_MANUAL, fn ($q) => $q->whereIn('source', [self::SOURCE_PRE_RESTORE, self::SOURCE_SCHEDULED]))
            ->whereNotIn('id', $protectedIds)
            ->orderBy('created_at');

        $evictOne = function () use ($candidateQuery) : ?BackupSnapshot {
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

    private function pathFor(int $userId) : string
    {
        return sprintf('u%d/%s.json', $userId, (string) Str::ulid());
    }

    // ------------------------------------------------------------------
    // Restore engine (plan phase 4)
    // ------------------------------------------------------------------

    /** Confirmation-token TTL in seconds. */
    public const TOKEN_TTL = 600;

    /**
     * Stable content columns for diff + diffHash (RT-7). Volatile columns —
     * last_used_at (written by every OTP fetch), counter (persisted by HOTP
     * generation), created_at/updated_at — are deliberately excluded,
     * otherwise an actively-used vault 409-loops forever.
     */
    private const STABLE_COLUMNS = [
        'service', 'account', 'secret', 'encrypted', 'otp_type', 'algorithm',
        'digits', 'period', 'group_id', 'icon', 'notes', 'is_pinned',
        'recovery_codes', 'order_column',
    ];

    /**
     * Dry-run: compute the diff a restore would apply and issue a
     * confirmation token bound to mode + diffHash. No mutation.
     */
    public function dryRun(User $user, BackupSnapshot $snapshot, string $mode) : array
    {
        $payload = $this->loadSnapshotPayload($snapshot);
        $diff    = $this->computeDiff($user, $payload);
        $token   = $this->issueRestoreToken($user, $snapshot, $mode, $diff['diff_hash']);

        return [
            'mode'                 => $mode,
            'token'                => $token,
            'expires_at'           => now()->utc()->addSeconds(self::TOKEN_TTL)->toIso8601String(),
            'to_create'            => $diff['to_create'],
            'to_update'            => $diff['to_update'],
            'to_delete'            => $diff['to_delete'],
            'unchanged_count'      => $diff['unchanged_count'],
            'warnings'             => $diff['warnings'],
            'key_mismatch_warning' => $this->saltChangedSince($snapshot, $user),
        ];
    }

    /**
     * Restore the snapshot. Merge = non-destructive upsert with per-account
     * isolation. Replace = wipe-and-replace inside ONE transaction (any
     * account-level exception aborts everything), always preceded by an
     * automatic pre_restore safety snapshot, with a typed `RESTORE`
     * confirmation on top of the server token.
     *
     * @return array<string, mixed>
     */
    public function restore(User $user, BackupSnapshot $snapshot, string $mode, string $token, ?string $confirm = null) : array
    {
        if (! in_array($mode, ['merge', 'replace'], true)) {
            throw new \InvalidArgumentException('Invalid restore mode.');
        }

        // Payload loaded FIRST, before any mutation (RT-6): the pre-restore
        // snapshot must not capture a half-applied state, and eviction of the
        // restore target must be impossible (protected_ids below).
        $payload = $this->loadSnapshotPayload($snapshot);

        if ($mode === 'replace') {
            if ($confirm !== 'RESTORE') {
                throw new \InvalidArgumentException('Typed confirmation required for replace mode.');
            }

            // Cheap non-consuming peek so a bogus/expired attempt does not
            // burn a pre_restore safety snapshot (which would evict older
            // pre_restore lanes). The authoritative consume — including the
            // drift-hash check — stays inside the transaction below, so a
            // drifted vault can still land here once; that is acceptable.
            if (! $this->tokenMatches(Cache::get($this->tokenKey($user->id, $snapshot->id)), $token)) {
                throw new RestoreTokenInvalidException;
            }

            $this->createSnapshot(
                $user,
                self::SOURCE_PRE_RESTORE,
                sprintf('pre-restore of snapshot #%d', $snapshot->id),
                [$snapshot->id],
            );
        }

        $iconCleanup = [];

        $result = DB::transaction(function () use ($user, $payload, $mode, $token, $snapshot, &$iconCleanup) {
            // TOCTOU (RT-7): lock the rows, recompute the diff, re-validate
            // the hash — a drifted vault answers 409 restore_state_changed.
            TwoFAccount::where('user_id', $user->id)->lockForUpdate()->get();

            $diff = $this->computeDiff($user, $payload);

            // Consume atomically: the account-row lock above serializes
            // non-empty vaults, but locks NOTHING on a zero-row vault —
            // without this lock two concurrent restores could both read the
            // same token. A loser timed out of the lock has, by definition,
            // been beaten to the single-use token.
            try {
                $tokenData = Cache::lock('restore-token-consume:' . $user->id . ':' . $snapshot->id, 5)
                    ->block(5, fn () => $this->consumeRestoreToken($user->id, $snapshot->id));
            } catch (LockTimeoutException) {
                throw new RestoreTokenInvalidException;
            }
            if (! $this->tokenMatches($tokenData, $token)) {
                throw new RestoreTokenInvalidException;
            }
            if ($tokenData['mode'] !== $mode || $tokenData['diff_hash'] !== $diff['diff_hash']) {
                throw new RestoreStateChangedException;
            }

            // Replace: event-less bulk delete of accounts absent from the
            // snapshot (RT-2). Mass delete fires no model events — the FK
            // cascade cleans shared_accounts at the DB level, OTP logs are
            // bulk-deleted explicitly, and exactly ONE summary audit entry is
            // written after commit instead of N per-account entries.
            $deletedIds = [];
            if ($mode === 'replace' && count($diff['to_delete']) > 0) {
                $deletedIds = array_column($diff['to_delete'], 'id');
                // Icon names referenced by the rows about to disappear —
                // collected BEFORE the delete.
                $iconCleanup = TwoFAccount::whereIn('id', $deletedIds)
                    ->get(['id', 'icon'])
                    ->flatMap(fn (TwoFAccount $a) => (array) ($a->icon ?? []))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                TwoFAccount::whereIn('id', $deletedIds)->delete();
                OtpLog::whereIn('twofaccount_id', $deletedIds)->delete();
            }

            // Shared full-column upsert for BOTH modes (RT-5) — restore is a
            // true time-machine, unlike the lossy external-format import.
            // Replace: isolation OFF (any exception aborts everything).
            // Merge: per-account isolation, like import.
            $counts = ['created' => 0, 'updated' => 0, 'failed' => 0];
            $errors = [];

            [$groupMap, $groupsCreated] = $this->buildGroupMap($user, $payload['groups'] ?? []);

            foreach ($payload['accounts'] as $accountData) {
                $target = $this->resolveTarget($user, $accountData);
                try {
                    $this->upsertAccount($user, $accountData, $target, $groupMap);
                    $target === null ? $counts['created']++ : $counts['updated']++;
                } catch (\Throwable $e) {
                    if ($mode === 'replace') {
                        throw $e;
                    }
                    $counts['failed']++;
                    $errors[] = [
                        'service' => $accountData['service'] ?? 'Unknown',
                        'account' => $accountData['account'] ?? '',
                        'error'   => $e->getMessage(),
                    ];
                }
            }

            return [
                'counts'         => $counts,
                'deleted_ids'    => $deletedIds,
                'groups_created' => $groupsCreated,
                'errors'         => $errors,
            ];
        });

        // Post-commit: garbage-collect icon files no surviving account
        // references (disk deletes are not rollback-able, so this MUST happen
        // after commit — the rollback path deletes nothing from disk).
        if (count($iconCleanup) > 0) {
            $this->reconcileIcons($iconCleanup);
        }

        // Exactly ONE summary audit entry per restore (RT-2: never N
        // per-account entries — the wipe fires no model events).
        app(PersonalActivityLogger::class)->log($user, PersonalAction::BACKUP_RESTORED, [
            'snapshot_id' => $snapshot->id,
            'mode'        => $mode,
            'created'     => $result['counts']['created'],
            'updated'     => $result['counts']['updated'],
            'deleted'     => count($result['deleted_ids']),
        ]);

        return [
            'created_count'        => $result['counts']['created'],
            'updated_count'        => $result['counts']['updated'],
            'deleted_count'        => count($result['deleted_ids']),
            'failed_count'         => $result['counts']['failed'],
            'errors'               => $result['errors'],
            'groups_created'       => $result['groups_created'],
            'warnings'             => [],
            'key_mismatch_warning' => $this->saltChangedSince($snapshot, $user),
        ];
    }

    // ---- Diff (RT-4: never SQL on encrypted-at-rest columns) ----

    /**
     * Match snapshot rows against the current vault primarily by row id
     * (same-vault restore), falling back to in-PHP comparison of DECODED
     * service+account for rows whose id no longer exists. Every fallback
     * match is disclosed as a warning (the C10 degradation made visible).
     *
     * @return array<string, mixed>
     */
    public function computeDiff(User $user, array $payload) : array
    {
        $current = TwoFAccount::where('user_id', $user->id)->get()->keyBy('id');

        $toCreate  = [];
        $toUpdate  = [];
        $toDelete  = [];
        $unchanged = 0;
        $warnings  = [];
        /** @var array<int, bool> $matched */
        $matched = [];

        foreach ($payload['accounts'] ?? [] as $snap) {
            /** @var TwoFAccount|null $account */
            $account = isset($snap['id']) ? $current->get($snap['id']) : null;
            $basis   = 'id';

            if ($account === null) {
                // Fallback: decoded service+account comparison in PHP — the
                // at-rest columns are APP_KEY ciphertext under useEncryption
                // and never compare equal in SQL (RT-4).
                $account = $current->first(function (TwoFAccount $candidate) use ($snap, $matched) {
                    return ! isset($matched[$candidate->id])
                        && strcasecmp((string) $candidate->service, (string) ($snap['service'] ?? '')) === 0
                        && strcasecmp((string) $candidate->account, (string) ($snap['account'] ?? '')) === 0;
                });
                if ($account !== null) {
                    $basis      = 'decoded_service_account';
                    $warnings[] = sprintf(
                        'Account "%s / %s" matched by decoded service+account (its id changed since the snapshot).',
                        (string) ($snap['service'] ?? ''),
                        (string) ($snap['account'] ?? ''),
                    );
                }
            }

            if ($account === null) {
                $toCreate[] = [
                    'service'  => (string) ($snap['service'] ?? ''),
                    'account'  => (string) ($snap['account'] ?? ''),
                    'otp_type' => (string) ($snap['otp_type'] ?? 'totp'),
                ];

                continue;
            }

            $matched[$account->id] = true;

            if ($this->contentDiffers($account, $snap)) {
                $toUpdate[] = [
                    'id'          => $account->id,
                    'service'     => (string) ($snap['service'] ?? ''),
                    'account'     => (string) ($snap['account'] ?? ''),
                    'match_basis' => $basis,
                ];
            } else {
                $unchanged++;
            }
        }

        foreach ($current as $account) {
            if (! isset($matched[$account->id])) {
                $toDelete[] = [
                    'id'      => $account->id,
                    'service' => (string) $account->service,
                    'account' => (string) $account->account,
                ];
            }
        }

        // Canonical diffHash over identity + action only (not the full
        // payload) so a refetch produces a stable, order-independent value.
        $canonical = [
            'create' => array_map(fn ($r) => [$r['service'], $r['account'], $r['otp_type']], $toCreate),
            'update' => array_map(fn ($r) => $r['id'], $toUpdate),
            'delete' => array_map(fn ($r) => $r['id'], $toDelete),
        ];
        sort($canonical['create']);
        sort($canonical['update']);
        sort($canonical['delete']);

        return [
            'to_create'       => $toCreate,
            'to_update'       => $toUpdate,
            'to_delete'       => $toDelete,
            'unchanged_count' => $unchanged,
            'warnings'        => $warnings,
            'diff_hash'       => hash('sha256', (string) json_encode($canonical)),
        ];
    }

    /** Compare a current account against a snapshot row over stable columns. */
    private function contentDiffers(TwoFAccount $account, array $snap) : bool
    {
        foreach (self::STABLE_COLUMNS as $column) {
            $snapValue = $snap[$column] ?? null;

            $currentValue = match ($column) {
                'encrypted', 'is_pinned'           => (bool) $account->{$column},
                'digits', 'period', 'order_column' => $account->{$column} !== null ? (int) $account->{$column} : null,
                default                            => $account->{$column},
            };

            if ($currentValue !== $snapValue) {
                // null vs '' equivalence for legacy rows (import wrote '' for
                // missing service).
                if (in_array($column, ['service', 'account', 'notes', 'recovery_codes'], true)
                    && ($currentValue ?? '') === ($snapValue ?? '')) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    // ---- Tokens ----

    private function issueRestoreToken(User $user, BackupSnapshot $snapshot, string $mode, string $diffHash) : string
    {
        $token = Str::random(48);

        Cache::put(
            $this->tokenKey($user->id, $snapshot->id),
            ['token' => $token, 'mode' => $mode, 'diff_hash' => $diffHash],
            now()->addSeconds(self::TOKEN_TTL),
        );

        return $token;
    }

    /** Single-use: the entry is deleted on ANY restore attempt (RT-7). */
    private function consumeRestoreToken(int $userId, int $snapshotId) : ?array
    {
        $key  = $this->tokenKey($userId, $snapshotId);
        $data = Cache::get($key);

        Cache::forget($key);

        return is_array($data) ? $data : null;
    }

    private function tokenKey(int $userId, int $snapshotId) : string
    {
        return "restore-token:{$userId}:{$snapshotId}";
    }

    /** The cached token entry must exist and carry the exact token. */
    private function tokenMatches(?array $tokenData, string $token) : bool
    {
        return $tokenData !== null && hash_equals((string) $tokenData['token'], (string) $token);
    }

    // ---- Upsert ----

    /**
     * Map snapshot group ids to current group ids. Same-vault restores reuse
     * the group by id; missing groups are re-created by name (C6: unmapped
     * group ids never attach raw).
     *
     * @param  array<int, array<string, mixed>>  $snapshotGroups
     * @return array{0: array<int, int>, 1: int} [map, groupsCreated]
     */
    private function buildGroupMap(User $user, array $snapshotGroups) : array
    {
        $map           = [];
        $groupsCreated = 0;

        foreach ($snapshotGroups as $group) {
            $snapshotId = (int) ($group['id'] ?? 0);
            if ($snapshotId === 0) {
                continue;
            }

            $existing = Group::where('user_id', $user->id)->find($snapshotId);
            if ($existing) {
                $map[$snapshotId] = $existing->id;

                continue;
            }

            $byName = Group::where('user_id', $user->id)->where('name', $group['name'] ?? '')->first();
            if ($byName) {
                $map[$snapshotId] = $byName->id;

                continue;
            }

            $created               = new Group;
            $created->user_id      = $user->id;
            $created->name         = (string) ($group['name'] ?? 'Imported group');
            $created->order_column = $group['order_column'] ?? null;
            $created->save();
            $groupsCreated++;
            $map[$snapshotId] = $created->id;
        }

        return [$map, $groupsCreated];
    }

    /**
     * Resolve the upsert target for a snapshot row: the same-id account when
     * it still exists (id-primary), else a decoded service+account fallback,
     * else null (create new).
     */
    private function resolveTarget(User $user, array $accountData) : ?TwoFAccount
    {
        if (! empty($accountData['id'])) {
            $byId = TwoFAccount::where('user_id', $user->id)->find($accountData['id']);
            if ($byId) {
                return $byId;
            }
        }

        return TwoFAccount::where('user_id', $user->id)->get()
            ->first(fn (TwoFAccount $a) => strcasecmp((string) $a->service, (string) ($accountData['service'] ?? '')) === 0
                && strcasecmp((string) $a->account, (string) ($accountData['account'] ?? '')) === 0);
    }

    /** Full-column assignment — every field the snapshot carried. */
    private function upsertAccount(User $user, array $data, ?TwoFAccount $target, array $groupMap) : void
    {
        $account = $target ?? new TwoFAccount;

        $account->user_id   = $user->id;
        $account->service   = $data['service'] ?? '';
        $account->account   = $data['account'] ?? '';
        $account->secret    = $data['secret'];
        $account->encrypted = (bool) ($data['encrypted'] ?? false);
        $account->otp_type  = $data['otp_type'] ?? 'totp';
        $account->algorithm = $data['algorithm'] ?? 'sha1';
        $account->digits    = (int) ($data['digits'] ?? 6);
        // Null passes through for HOTP rows (period is TOTP-only); the
        // model's setPeriodAttribute mutator defaults TOTP to 30. Hardcoding
        // 30 here would make every restored HOTP row differ from its
        // snapshot forever, poisoning future diffs.
        $account->period  = isset($data['period']) ? (int) $data['period'] : null;
        $account->counter = $data['counter'] ?? null;
        $account->icon    = $data['icon'] ?? null;
        // Full-column parity (phase 3): the import path drops these, restore must not.
        $account->notes          = $data['notes'] ?? null;
        $account->is_pinned      = (bool) ($data['is_pinned'] ?? false);
        $account->recovery_codes = $data['recovery_codes'] ?? null;
        // C6: unmapped group ids never attach raw.
        $snapshotGroupId   = $data['group_id'] ?? null;
        $account->group_id = $snapshotGroupId !== null && isset($groupMap[(int) $snapshotGroupId])
            ? $groupMap[(int) $snapshotGroupId]
            : null;

        // Preserve the snapshot's ordering explicitly (sortable only
        // auto-assigns when the column is null).
        $account->order_column = $data['order_column'] ?? $account->order_column;

        // last_used_at is restored too? NO — volatile; the vault's usage
        // history is current truth, not snapshot truth (RT-7).

        $account->save();
    }

    // ---- Icon reconciliation (RT-2) ----

    /**
     * Delete icon FILES the wiped rows referenced but that no surviving
     * account (any user) references anymore. Only safe AFTER commit — the
     * rollback path must never touch the disk.
     *
     * @param  array<int, string>  $candidateNames
     */
    private function reconcileIcons(array $candidateNames) : void
    {
        if (count($candidateNames) === 0) {
            return;
        }

        $surviving = TwoFAccount::whereNotNull('icon')
            ->get(['icon'])
            ->flatMap(fn (TwoFAccount $a) => (array) ($a->icon ?? []))
            ->filter()
            ->unique();

        $unreferenced = collect($candidateNames)
            ->reject(fn (string $name) => $surviving->contains($name))
            ->values()
            ->all();

        if (count($unreferenced) > 0) {
            IconStore::delete($unreferenced);
            Log::info(sprintf('Restore icon reconciliation removed %d unreferenced icon file(s)', count($unreferenced)));
        }
    }
}
