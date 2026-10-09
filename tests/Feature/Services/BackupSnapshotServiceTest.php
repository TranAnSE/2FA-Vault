<?php

namespace Tests\Feature\Services;

use App\Exceptions\SnapshotsUnreadableException;
use App\Models\BackupSnapshot;
use App\Models\Group;
use App\Models\TwoFAccount;
use App\Models\User;
use App\Services\BackupSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BackupSnapshotService Tests
 *
 * Snapshot lifecycle: payload parity (full column projection incl. the
 * import-path parity-gap fields), quota lanes (RT-6), checksum + APP_KEY
 * fingerprint (RT-13), rotation metadata, orphan pruning.
 */
class BackupSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    private BackupSnapshotService $service;

    private User $user;

    protected function setUp() : void
    {
        parent::setUp();

        $this->service = app(BackupSnapshotService::class);
        $this->user    = User::factory()->create();
        Storage::fake('snapshots');
    }

    /** Create an account exercising decoded fields (E2EE covered separately). */
    private function makeAccount(array $overrides = []) : TwoFAccount
    {
        $account                 = new TwoFAccount;
        $account->user_id        = $this->user->id;
        $account->service        = 'GitHub';
        $account->account        = 'alice@example.com';
        $account->secret         = 'JBSWY3DPEHPK3PXP';
        $account->otp_type       = 'totp';
        $account->digits         = 8;
        $account->period         = 60;
        $account->algorithm      = 'sha256';
        $account->notes          = 'my secret note';
        $account->is_pinned      = true;
        $account->recovery_codes = json_encode(['1111-2222']);
        foreach ($overrides as $key => $value) {
            $account->{$key} = $value;
        }
        $account->save();

        return $account;
    }

    public function test_create_snapshot_writes_encrypted_file_and_row() : void
    {
        $this->makeAccount();

        $snapshot = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL, 'before cleanup');

        $this->assertDatabaseHas('backup_snapshots', [
            'id'             => $snapshot->id,
            'user_id'        => $this->user->id,
            'source'         => 'manual',
            'label'          => 'before cleanup',
            'accounts_count' => 1,
        ]);
        Storage::disk('snapshots')->assertExists($snapshot->file_path);

        // The file is APP_KEY-encrypted snapshot-1 JSON, not plaintext.
        $raw = Storage::disk('snapshots')->get($snapshot->file_path);
        $this->assertStringNotContainsString('GitHub', $raw);
        $payload = json_decode(Crypt::decryptString($raw), true);
        $this->assertSame(BackupSnapshotService::PAYLOAD_FORMAT, $payload['format']);
        $this->assertSame($this->user->encryption_salt, $payload['user']['encryption_salt']);
    }

    public function test_payload_carries_full_column_projection_including_parity_gap_fields() : void
    {
        $account = $this->makeAccount();

        $snapshot = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL);
        $payload  = $this->service->loadSnapshotPayload($snapshot);

        $row = $payload['accounts'][0];
        $this->assertSame($account->id, $row['id']);
        $this->assertSame('GitHub', $row['service']);
        $this->assertSame('my secret note', $row['notes']);
        $this->assertTrue($row['is_pinned']);
        $this->assertSame(['1111-2222'], json_decode($row['recovery_codes'], true));
        $this->assertSame($account->order_column, $row['order_column']);
        $this->assertSame(8, $row['digits']);
        $this->assertSame('sha256', $row['algorithm']);

        $group          = new Group;
        $group->name    = 'Work';
        $group->user_id = $this->user->id;
        $group->save();

        $snapshot2 = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL);
        $payload2  = $this->service->loadSnapshotPayload($snapshot2);
        $this->assertCount(1, $payload2['groups']);
        $this->assertSame($group->id, $payload2['groups'][0]['id']);
        $this->assertSame('Work', $payload2['groups'][0]['name']);
    }

    public function test_e2ee_secret_stays_ciphertext_in_payload() : void
    {
        $envelope = json_encode([
            'ciphertext' => base64_encode(random_bytes(32)),
            'iv'         => base64_encode(random_bytes(12)),
            'authTag'    => base64_encode(random_bytes(16)),
        ]);
        $this->makeAccount(['secret' => $envelope, 'encrypted' => true]);

        $snapshot = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL);
        $payload  = $this->service->loadSnapshotPayload($snapshot);

        // The E2EE envelope passes through untouched (server never decrypts).
        $this->assertSame($envelope, $payload['accounts'][0]['secret']);
        $this->assertTrue($payload['accounts'][0]['encrypted']);
    }

    public function test_checksum_detects_tampering() : void
    {
        $this->makeAccount();
        $snapshot = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL);

        // Tamper with the file on disk.
        Storage::disk('snapshots')->put($snapshot->file_path, Crypt::encryptString('{"format":"snapshot-1","tampered":true}'));

        $this->expectException(SnapshotsUnreadableException::class);
        $this->service->loadSnapshotPayload($snapshot);
    }

    public function test_app_key_rotation_surfaces_unreadable_reason() : void
    {
        $this->makeAccount();
        $snapshot = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL);
        $this->assertNull($this->service->unreadableReason($snapshot));

        // Simulate an APP_KEY rotation.
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);

        $this->assertSame('app_key_rotated', $this->service->unreadableReason($snapshot));
        $this->expectException(SnapshotsUnreadableException::class);
        $this->service->loadSnapshotPayload($snapshot);
    }

    public function test_snapshot_records_rotation_metadata() : void
    {
        $this->makeAccount();
        $snapshot = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL);

        $this->assertSame($this->user->encryption_salt, $snapshot->encryption_salt_at_snapshot);
        $this->assertSame($this->user->encryption_version, $snapshot->encryption_version_at_snapshot);
        $this->assertFalse($this->service->saltChangedSince($snapshot, $this->user));

        // Master-password rotation changes the user's salt.
        $this->user->encryption_salt = base64_encode(random_bytes(32));
        $this->assertTrue($this->service->saltChangedSince($snapshot, $this->user));
    }

    // ---- Quota lanes (RT-6) ----

    public function test_count_quota_evicts_oldest_manual_when_manual_create_overflows() : void
    {
        config(['2fauth.config.snapshotMaxCount' => 3]);

        $this->travelTo(now()->setTime(10, 0));
        $first = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL, 'oldest');
        $this->travelTo(now()->setTime(10, 1));
        $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL, 'middle');
        $this->travelTo(now()->setTime(10, 2));
        $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL, 'newest');
        $this->travelTo(now()->setTime(10, 3));
        $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL, 'overflow');

        $this->assertDatabaseMissing('backup_snapshots', ['id' => $first->id]);
        $this->assertSame(3, BackupSnapshot::where('user_id', $this->user->id)->count());
        Storage::disk('snapshots')->assertMissing($first->file_path);
    }

    public function test_automatic_source_never_evicts_manual_snapshots() : void
    {
        config(['2fauth.config.snapshotMaxCount' => 2]);

        $this->travelTo(now()->setTime(10, 0));
        $manual = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL);
        $this->travelTo(now()->setTime(10, 1));
        $preRestore = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_PRE_RESTORE);

        $this->travelTo(now()->setTime(10, 2));
        // Over quota — but a pre_restore create must not evict the manual.
        $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_PRE_RESTORE);

        $this->assertDatabaseHas('backup_snapshots', ['id' => $manual->id]);
        $this->assertDatabaseMissing('backup_snapshots', ['id' => $preRestore->id]);
        $this->assertSame(2, BackupSnapshot::where('user_id', $this->user->id)->count());
    }

    public function test_pre_restore_evicts_oldest_pre_restore_before_scheduled() : void
    {
        config(['2fauth.config.snapshotMaxCount' => 2]);

        $this->travelTo(now()->setTime(10, 0));
        $oldPreRestore = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_PRE_RESTORE);
        $this->travelTo(now()->setTime(10, 1));
        $scheduled = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_SCHEDULED);

        $this->travelTo(now()->setTime(10, 2));
        $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_PRE_RESTORE);

        $this->assertDatabaseMissing('backup_snapshots', ['id' => $oldPreRestore->id]);
        $this->assertDatabaseHas('backup_snapshots', ['id' => $scheduled->id]);
    }

    public function test_protected_ids_are_never_evicted() : void
    {
        config(['2fauth.config.snapshotMaxCount' => 1]);

        $this->travelTo(now()->setTime(10, 0));
        $protected = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_PRE_RESTORE);
        $this->travelTo(now()->setTime(10, 1));
        $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_PRE_RESTORE, null, [$protected->id]);

        $this->assertDatabaseHas('backup_snapshots', ['id' => $protected->id]);
        $this->assertSame(2, BackupSnapshot::where('user_id', $this->user->id)->count());
    }

    public function test_delete_snapshot_removes_file_and_row() : void
    {
        $snapshot = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL);

        $this->service->deleteSnapshot($snapshot);

        $this->assertDatabaseMissing('backup_snapshots', ['id' => $snapshot->id]);
        Storage::disk('snapshots')->assertMissing($snapshot->file_path);
    }

    public function test_prune_orphans_removes_files_without_rows_and_rows_without_files() : void
    {
        $snapshot = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL);

        // Rollback leftover: a file with no row.
        Storage::disk('snapshots')->put('u' . $this->user->id . '/orphan.json', 'garbage');
        // Dead row: a row whose file is gone — backdated past the prune grace
        // window (a FRESH dead row may predate its file write and must
        // survive the sweep).
        $dead = BackupSnapshot::create([
            'user_id'             => $this->user->id,
            'source'              => 'manual',
            'accounts_count'      => 0,
            'groups_count'        => 0,
            'size_bytes'          => 1,
            'checksum'            => str_repeat('0', 64),
            'app_key_fingerprint' => $this->service->appKeyFingerprint(),
            'file_path'           => 'u' . $this->user->id . '/ghost.json',
        ]);
        BackupSnapshot::whereKey($dead->id)->update(['created_at' => now()->subMinutes(10)]);

        $result = $this->service->pruneOrphans();

        // A fresh dead row (file not landed yet, or just deleted) is inside
        // the grace window and must NOT be judged dead.
        $fresh = BackupSnapshot::create([
            'user_id'             => $this->user->id,
            'source'              => 'manual',
            'accounts_count'      => 0,
            'groups_count'        => 0,
            'size_bytes'          => 1,
            'checksum'            => str_repeat('0', 64),
            'app_key_fingerprint' => $this->service->appKeyFingerprint(),
            'file_path'           => 'u' . $this->user->id . '/inflight.json',
        ]);

        $this->assertSame(1, $result['files_removed']);
        $this->assertSame(1, $result['rows_removed']);
        Storage::disk('snapshots')->assertMissing('u' . $this->user->id . '/orphan.json');
        $this->assertDatabaseMissing('backup_snapshots', ['id' => $dead->id]);
        $this->assertDatabaseHas('backup_snapshots', ['id' => $snapshot->id]);
        $this->assertDatabaseHas('backup_snapshots', ['id' => $fresh->id]);
    }

    public function test_byte_quota_cannot_evict_manual_for_automatic_sources() : void
    {
        config(['2fauth.config.snapshotMaxTotalMb' => 0.0001]); // ~105 bytes — every payload exceeds it
        config(['2fauth.config.snapshotMaxCount' => 100]);

        $big = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL, 'big');
        $this->assertGreaterThan(105, $big->size_bytes);

        $this->travelTo(now()->addMinute());
        $second = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_PRE_RESTORE);

        // The byte quota cannot act: the only candidate is a manual snapshot,
        // which automatic sources must never evict — both snapshots survive.
        $this->assertDatabaseHas('backup_snapshots', ['id' => $big->id]);
        $this->assertDatabaseHas('backup_snapshots', ['id' => $second->id]);
    }
}
