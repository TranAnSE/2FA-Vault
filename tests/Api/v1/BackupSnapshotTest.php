<?php

namespace Tests\Api\v1;

use App\Models\BackupSnapshot;
use App\Models\TwoFAccount;
use App\Models\User;
use App\Services\BackupSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Backup snapshot API tests: auth, scopes, ownership (404 for other users),
 * validation, and the APP_KEY-rotation surface on the list endpoint.
 */
class BackupSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp() : void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        Storage::fake('snapshots');
    }

    private function createSnapshotFor(User $user, string $source = 'manual') : BackupSnapshot
    {
        /** @var BackupSnapshotService $service */
        $service = app(BackupSnapshotService::class);

        return $service->createSnapshot($user, $source);
    }

    public function test_list_returns_empty_array_without_snapshots() : void
    {
        $this->getJson('/api/v1/backups/snapshots')
            ->assertStatus(200)
            ->assertJsonCount(0);
    }

    public function test_list_returns_own_snapshots_with_metadata() : void
    {
        $this->createSnapshotFor($this->user, 'manual');

        $this->getJson('/api/v1/backups/snapshots')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonStructure([[
                'id', 'source', 'label', 'accounts_count', 'groups_count',
                'size_bytes', 'created_at', 'salt_changed_since_snapshot', 'unreadable_reason',
            ]])
            ->assertJsonPath('0.source', 'manual')
            ->assertJsonPath('0.unreadable_reason', null)
            ->assertJsonPath('0.salt_changed_since_snapshot', false);
    }

    public function test_list_flags_snapshots_after_app_key_rotation() : void
    {
        $this->createSnapshotFor($this->user, 'manual');

        // Simulate an APP_KEY rotation.
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);

        $this->getJson('/api/v1/backups/snapshots')
            ->assertStatus(200)
            ->assertJsonPath('0.unreadable_reason', 'app_key_rotated');
    }

    public function test_store_creates_manual_snapshot() : void
    {
        $this->postJson('/api/v1/backups/snapshots', ['label' => 'before experiments'])
            ->assertStatus(201)
            ->assertJsonPath('source', 'manual')
            ->assertJsonPath('label', 'before experiments');

        $this->assertDatabaseHas('backup_snapshots', [
            'user_id' => $this->user->id,
            'source'  => 'manual',
        ]);
    }

    public function test_store_rejects_overlong_label() : void
    {
        $this->postJson('/api/v1/backups/snapshots', ['label' => str_repeat('x', 101)])
            ->assertStatus(422);
    }

    public function test_destroy_removes_own_snapshot() : void
    {
        $snapshot = $this->createSnapshotFor($this->user);

        $this->deleteJson('/api/v1/backups/snapshots/' . $snapshot->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('backup_snapshots', ['id' => $snapshot->id]);
        Storage::disk('snapshots')->assertMissing($snapshot->file_path);
    }

    public function test_other_users_snapshots_are_invisible() : void
    {
        $other   = User::factory()->create();
        $foreign = $this->createSnapshotFor($other);

        // 404, not 403 — avoid existence enumeration.
        $this->deleteJson('/api/v1/backups/snapshots/' . $foreign->id)
            ->assertStatus(404);

        // And never listed.
        $this->getJson('/api/v1/backups/snapshots')
            ->assertStatus(200)
            ->assertJsonCount(0);
    }

    public function test_endpoints_require_authentication() : void
    {
        $this->refreshApplication();

        $this->getJson('/api/v1/backups/snapshots')->assertStatus(401);
        $this->postJson('/api/v1/backups/snapshots')->assertStatus(401);
        $this->deleteJson('/api/v1/backups/snapshots/1')->assertStatus(401);
    }

    public function test_snapshot_preference_writes_are_accepted() : void
    {
        // RT-9: snapshot_frequency/snapshot_time must be registered in the
        // $preferences whitelist, else PUT user/preferences/{name} 404s.
        $this->putJson('/api/v1/user/preferences/snapshot_frequency', ['value' => 'weekly'])
            ->assertStatus(201)
            ->assertJsonPath('value', 'weekly');

        $this->putJson('/api/v1/user/preferences/snapshot_time', ['value' => '03:30'])
            ->assertStatus(201)
            ->assertJsonPath('value', '03:30');

        $this->putJson('/api/v1/user/preferences/snapshot_frequency', ['value' => 'bogus'])
            ->assertStatus(422);
    }

    public function test_auto_backup_preferences_are_registered_drive_by() : void
    {
        // Drive-by RT-9 fix: auto_backup_* keys were missing from the
        // whitelist even though the auto-backup feature writes them.
        $this->putJson('/api/v1/user/preferences/auto_backup_enabled', ['value' => true])
            ->assertStatus(201)
            ->assertJsonPath('value', true);
    }

    // ---- Dry-run + restore endpoints (plan phase 4) ----

    public function test_dry_run_returns_diff_with_token() : void
    {
        $this->createSnapshotFor($this->user);

        $this->postJson('/api/v1/backups/snapshots/1/dry-run', ['mode' => 'merge'])
            ->assertStatus(200)
            ->assertJsonStructure([
                'mode', 'token', 'expires_at', 'to_create', 'to_update', 'to_delete',
                'unchanged_count', 'warnings', 'key_mismatch_warning',
            ])
            ->assertJsonPath('mode', 'merge');
    }

    public function test_dry_run_validates_mode() : void
    {
        $this->createSnapshotFor($this->user);

        $this->postJson('/api/v1/backups/snapshots/1/dry-run', ['mode' => 'obliterate'])
            ->assertStatus(422);

        $this->postJson('/api/v1/backups/snapshots/1/dry-run', [])
            ->assertStatus(422);
    }

    public function test_dry_run_of_foreign_snapshot_is_404() : void
    {
        $foreign = $this->createSnapshotFor(User::factory()->create());

        $this->postJson('/api/v1/backups/snapshots/' . $foreign->id . '/dry-run', ['mode' => 'merge'])
            ->assertStatus(404);
    }

    public function test_restore_merge_round_trip_via_api() : void
    {
        Storage::fake('snapshots');
        $service           = app(BackupSnapshotService::class);
        $account           = new TwoFAccount;
        $account->user_id  = $this->user->id;
        $account->service  = 'GitHub';
        $account->account  = 'alice@example.com';
        $account->secret   = 'JBSWY3DPEHPK3PXP';
        $account->otp_type = 'totp';
        $account->save();
        $snapshot = $service->createSnapshot($this->user, 'manual');
        $account->delete();

        $diff = $this->postJson('/api/v1/backups/snapshots/' . $snapshot->id . '/dry-run', ['mode' => 'merge'])
            ->assertStatus(200)
            ->assertJsonCount(1, 'to_create')
            ->json();

        $this->postJson('/api/v1/backups/snapshots/' . $snapshot->id . '/restore', [
            'mode'  => 'merge',
            'token' => $diff['token'],
        ])->assertStatus(200)
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('deleted_count', 0);

        $this->assertSame('GitHub', TwoFAccount::where('user_id', $this->user->id)->value('service'));

        // The token is single-use — replay answers restore_token_invalid.
        $this->postJson('/api/v1/backups/snapshots/' . $snapshot->id . '/restore', [
            'mode'  => 'merge',
            'token' => $diff['token'],
        ])->assertStatus(422)
            ->assertJsonPath('code', 'restore_token_invalid');
    }

    public function test_restore_replace_requires_typed_confirmation() : void
    {
        $snapshot = $this->createSnapshotFor($this->user);

        $diff = $this->postJson('/api/v1/backups/snapshots/' . $snapshot->id . '/dry-run', ['mode' => 'replace'])
            ->assertStatus(200)
            ->json();

        $this->postJson('/api/v1/backups/snapshots/' . $snapshot->id . '/restore', [
            'mode'    => 'replace',
            'token'   => $diff['token'],
            'confirm' => 'restore',
        ])->assertStatus(422);
    }

    public function test_restore_state_drift_maps_to_409() : void
    {
        $account           = new TwoFAccount;
        $account->user_id  = $this->user->id;
        $account->service  = 'GitHub';
        $account->account  = 'alice@example.com';
        $account->secret   = 'JBSWY3DPEHPK3PXP';
        $account->otp_type = 'totp';
        $account->save();
        $snapshot = $this->createSnapshotFor($this->user);

        $diff = $this->postJson('/api/v1/backups/snapshots/' . $snapshot->id . '/dry-run', ['mode' => 'merge'])
            ->assertStatus(200)
            ->json();

        // Vault drifts AFTER the dry-run.
        $account->notes = 'drift';
        $account->save();

        $this->postJson('/api/v1/backups/snapshots/' . $snapshot->id . '/restore', [
            'mode'  => 'merge',
            'token' => $diff['token'],
        ])->assertStatus(409)
            ->assertJsonPath('code', 'restore_state_changed');
    }

    public function test_restore_of_foreign_snapshot_is_404() : void
    {
        $foreign = $this->createSnapshotFor(User::factory()->create());

        $this->postJson('/api/v1/backups/snapshots/' . $foreign->id . '/restore', [
            'mode'  => 'merge',
            'token' => 'whatever',
        ])->assertStatus(404);
    }
}
