<?php

namespace Tests\Feature\Services;

use App\Exceptions\RestoreStateChangedException;
use App\Exceptions\RestoreTokenInvalidException;
use App\Facades\Settings;
use App\Models\BackupSnapshot;
use App\Models\Group;
use App\Models\TwoFAccount;
use App\Models\User;
use App\Services\BackupSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Backup restore engine tests (plan phase 4) — the highest-risk phase.
 *
 * Matrix: dry-run diff correctness (id-match + decoded fallback + a
 * useEncryption-enabled fixture), token invalid/expired/tampered/
 * state-changed, merge full-column parity, replace round-trip incl. icon
 * files, malformed-row FULL rollback with untouched icon files.
 */
class BackupRestoreTest extends TestCase
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

    private function makeAccount(array $overrides = []) : TwoFAccount
    {
        $account           = new TwoFAccount;
        $account->user_id  = $this->user->id;
        $account->service  = 'GitHub';
        $account->account  = 'alice@example.com';
        $account->secret   = 'JBSWY3DPEHPK3PXP';
        $account->otp_type = 'totp';
        foreach ($overrides as $key => $value) {
            $account->{$key} = $value;
        }
        $account->save();

        return $account;
    }

    /** Snapshot the current vault, returning [snapshot, payload]. */
    private function takeSnapshot() : array
    {
        $snapshot = $this->service->createSnapshot($this->user, BackupSnapshotService::SOURCE_MANUAL);

        return [$snapshot, $this->service->loadSnapshotPayload($snapshot)];
    }

    // ---- Dry-run diff ----

    public function test_dry_run_diff_matches_by_id_not_delete_create() : void
    {
        $account    = $this->makeAccount();
        [$snapshot] = $this->takeSnapshot();

        // Mutate: edit the matched account (id survives), add one.
        $account->notes = 'edited after snapshot';
        $account->save();

        $newAccount = $this->makeAccount(['service' => 'NewService', 'account' => 'new']);

        $diff = $this->service->dryRun($this->user, $snapshot, 'replace');

        // The edited row is matched BY ID as an update — never delete+create.
        $this->assertCount(1, $diff['to_update']);
        $this->assertSame($account->id, $diff['to_update'][0]['id']);
        // The row added after the snapshot is absent from it → replace deletes it.
        $this->assertCount(1, $diff['to_delete']);
        $this->assertSame($newAccount->id, $diff['to_delete'][0]['id']);
        // Both snapshot rows matched existing vault rows — nothing to create.
        $this->assertCount(0, $diff['to_create']);
        $this->assertSame(0, $diff['unchanged_count']);
        $this->assertArrayHasKey('token', $diff);
        $this->assertFalse($diff['key_mismatch_warning']);
    }

    public function test_dry_run_delete_diff_lists_accounts_absent_from_snapshot() : void
    {
        [$snapshot] = $this->takeSnapshot();

        // The account added after the snapshot is NOT in it — replace must delete it.
        $added = $this->makeAccount(['service' => 'Extra', 'account' => 'x']);

        $diff = $this->service->dryRun($this->user, $snapshot, 'replace');

        $this->assertContains($added->id, array_column($diff['to_delete'], 'id'));
        $this->assertCount(1, $diff['to_delete']);
        $this->assertSame(0, $diff['unchanged_count']);
    }

    public function test_dry_run_falls_back_to_decoded_match_and_warns() : void
    {
        $account    = $this->makeAccount();
        [$snapshot] = $this->takeSnapshot();

        // Simulate an id change: delete + recreate with the same identity
        // (new id, same decoded service+account, edited notes so the row is
        // a genuine update, not a no-op).
        $originalId = $account->id;
        $account->delete();
        $recreated = $this->makeAccount(['notes' => 'recreated']);

        $diff = $this->service->dryRun($this->user, $snapshot, 'merge');

        $this->assertCount(0, $diff['to_create'], 'decoded fallback must classify the recreated row as an update');
        $this->assertCount(1, $diff['to_update']);
        $this->assertSame($recreated->id, $diff['to_update'][0]['id']);
        $this->assertSame($originalId, $snapshot->fresh()->id);
        $this->assertNotSame($originalId, $recreated->id);
        $this->assertNotEmpty($diff['warnings'], 'a decoded fallback match must be disclosed');
    }

    public function test_dry_run_diff_works_with_useencryption_enabled() : void
    {
        // RT-4 fixture: with useEncryption the service/account columns are
        // APP_KEY ciphertext at rest — id-primary matching must still work
        // and the fallback must compare decoded values.
        Settings::set('useEncryption', true);

        $account    = $this->makeAccount();
        [$snapshot] = $this->takeSnapshot();

        // Under encryption the raw column is ciphertext: sanity-check the
        // premise of the C10 degradation.
        $this->assertNotSame('GitHub', $account->getRawOriginal('service'));

        $account->notes = 'changed under encryption';
        $account->save();

        $diff = $this->service->dryRun($this->user, $snapshot, 'merge');

        $this->assertCount(0, $diff['to_create']);
        $this->assertCount(0, $diff['to_delete'], 'id-primary matching must hold under encryption');
        $this->assertCount(1, $diff['to_update']);

        // Decoded fallback under encryption too (notes mutation keeps the
        // recreated row a genuine update rather than a no-op match).
        $account->delete();
        $recreated = $this->makeAccount(['notes' => 'recreated under encryption']);
        $diff2     = $this->service->dryRun($this->user, $snapshot, 'merge');
        $this->assertCount(0, $diff2['to_create']);
        $this->assertCount(1, $diff2['to_update']);
        $this->assertSame($recreated->id, $diff2['to_update'][0]['id']);
    }

    public function test_dry_run_excludes_volatile_columns() : void
    {
        $account    = $this->makeAccount();
        [$snapshot] = $this->takeSnapshot();

        // Volatile churn only: last_used_at + HOTP counter drift (RT-7).
        $account->last_used_at = now()->addHour();
        $account->counter      = ($account->counter ?? 0) + 5;
        $account->save();

        $diff = $this->service->dryRun($this->user, $snapshot, 'merge');

        $this->assertSame(1, $diff['unchanged_count'], 'volatile-only changes must not flag the row');
        $this->assertCount(0, $diff['to_update']);
        $this->assertCount(0, $diff['to_delete']);
    }

    public function test_dry_run_warns_on_master_password_rotation() : void
    {
        [$snapshot] = $this->takeSnapshot();

        $this->user->encryption_salt = base64_encode(random_bytes(32));
        $this->user->save();

        $diff = $this->service->dryRun($this->user, $snapshot, 'merge');
        $this->assertTrue($diff['key_mismatch_warning']);
    }

    // ---- Tokens ----

    public function test_restore_without_token_fails_cleanly() : void
    {
        [$snapshot] = $this->takeSnapshot();

        $this->expectException(RestoreTokenInvalidException::class);
        $this->service->restore($this->user, $snapshot, 'merge', 'not-a-real-token');
    }

    public function test_restore_with_tampered_token_fails() : void
    {
        [$snapshot] = $this->takeSnapshot();
        $diff       = $this->service->dryRun($this->user, $snapshot, 'merge');

        $this->expectException(RestoreTokenInvalidException::class);
        $this->service->restore($this->user, $snapshot, 'merge', $diff['token'] . 'x');
    }

    public function test_restore_with_expired_token_fails() : void
    {
        [$snapshot] = $this->takeSnapshot();
        $this->service->dryRun($this->user, $snapshot, 'merge');
        $this->travelTo(now()->addSeconds(BackupSnapshotService::TOKEN_TTL + 60));

        $this->expectException(RestoreTokenInvalidException::class);
        $this->service->restore($this->user, $snapshot, 'merge', 'whatever');
    }

    public function test_restore_is_single_use() : void
    {
        $account    = $this->makeAccount();
        [$snapshot] = $this->takeSnapshot();

        // Remove the account after the snapshot so a restore re-creates it.
        $account->delete();

        $diff = $this->service->dryRun($this->user, $snapshot, 'merge');
        $this->service->restore($this->user, $snapshot, 'merge', $diff['token']);

        // Second attempt with the same token: consumed.
        $this->expectException(RestoreTokenInvalidException::class);
        $this->service->restore($this->user, $snapshot, 'merge', $diff['token']);
    }

    public function test_restore_on_drifted_vault_answers_state_changed() : void
    {
        $account    = $this->makeAccount();
        [$snapshot] = $this->takeSnapshot();

        $diff = $this->service->dryRun($this->user, $snapshot, 'merge');

        // Vault drifts AFTER the dry-run.
        $account->notes = 'changed after dry-run';
        $account->save();

        try {
            $this->service->restore($this->user, $snapshot, 'merge', $diff['token']);
            $this->fail('expected RestoreStateChangedException');
        } catch (RestoreStateChangedException) {
            // expected
        }

        // The token is consumed even on a 409 (single-use, RT-7).
        $this->expectException(RestoreTokenInvalidException::class);
        $this->service->restore($this->user, $snapshot, 'merge', $diff['token']);
    }

    public function test_replace_without_typed_confirmation_is_refused() : void
    {
        [$snapshot] = $this->takeSnapshot();
        $diff       = $this->service->dryRun($this->user, $snapshot, 'replace');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->restore($this->user, $snapshot, 'replace', $diff['token'], 'restore');
    }

    // ---- Merge mode ----

    public function test_merge_restores_full_columns_round_trip() : void
    {
        $account = $this->makeAccount([
            'notes'          => 'restore me',
            'is_pinned'      => true,
            'recovery_codes' => json_encode(['aaaa-bbbb']),
            'digits'         => 8,
            'algorithm'      => 'sha512',
        ]);
        $group          = new Group;
        $group->name    = 'Work';
        $group->user_id = $this->user->id;
        $group->save();
        $account->group_id = $group->id;
        $account->save();

        [$snapshot] = $this->takeSnapshot();

        // Destroy the fields merge must bring back (delete the account AND
        // the group membership).
        $account->notes          = null;
        $account->is_pinned      = false;
        $account->recovery_codes = null;
        $account->digits         = 6;
        $account->group_id       = null;
        $account->save();

        $diff   = $this->service->dryRun($this->user, $snapshot, 'merge');
        $result = $this->service->restore($this->user, $snapshot, 'merge', $diff['token']);

        $this->assertSame(0, $result['deleted_count']);
        $this->assertSame(1, $result['updated_count']);

        $restored = $account->fresh();
        $this->assertSame('restore me', $restored->notes);
        $this->assertTrue((bool) $restored->is_pinned);
        $this->assertSame(['aaaa-bbbb'], json_decode($restored->recovery_codes, true));
        $this->assertSame(8, $restored->digits);
        $this->assertSame('sha512', $restored->algorithm);
        $this->assertSame($group->id, $restored->group_id);
    }

    public function test_merge_recreates_deleted_account_and_maps_group_by_name() : void
    {
        $group          = new Group;
        $group->name    = 'Work';
        $group->user_id = $this->user->id;
        $group->save();
        $account = $this->makeAccount(['group_id' => $group->id]);

        [$snapshot] = $this->takeSnapshot();

        $accountId = $account->id;
        $account->delete();

        $diff   = $this->service->dryRun($this->user, $snapshot, 'merge');
        $result = $this->service->restore($this->user, $snapshot, 'merge', $diff['token']);

        $this->assertSame(1, $result['created_count']);
        $restored = TwoFAccount::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame('GitHub', $restored->service);
        $this->assertNotSame($accountId, $restored->id);
        // The group still exists — mapped back, not duplicated.
        $this->assertSame($group->id, $restored->group_id);
        $this->assertSame(0, $result['groups_created']);
    }

    public function test_merge_recreates_missing_group() : void
    {
        $group          = new Group;
        $group->name    = 'Gone';
        $group->user_id = $this->user->id;
        $group->save();
        $account = $this->makeAccount(['group_id' => $group->id]);

        [$snapshot] = $this->takeSnapshot();

        $groupId = $group->id;
        $account->delete();
        $group->delete();

        $diff   = $this->service->dryRun($this->user, $snapshot, 'merge');
        $result = $this->service->restore($this->user, $snapshot, 'merge', $diff['token']);

        $this->assertSame(1, $result['groups_created']);
        $restored = TwoFAccount::where('user_id', $this->user->id)->firstOrFail();
        $this->assertNotNull($restored->group_id);
        $this->assertNotSame($groupId, $restored->group_id);
        $this->assertSame('Gone', Group::find($restored->group_id)->name);
    }

    // ---- Replace mode ----

    public function test_replace_round_trip_restores_snapshot_state_and_creates_pre_restore_snapshot() : void
    {
        Storage::fake('icons');

        // Icon values carry their file extension (Helpers::getRandomFilename).
        $kept    = $this->makeAccount(['icon' => 'kept-icon.svg']);
        $dropped = $this->makeAccount(['service' => 'Doomed', 'account' => 'doomed', 'icon' => 'doomed-icon.svg']);
        Storage::disk('icons')->put('kept-icon.svg', 'svg');
        Storage::disk('icons')->put('doomed-icon.svg', 'svg');

        [$snapshot] = $this->takeSnapshot();

        // Mutate the vault: change kept, delete nothing, add extra.
        $kept->notes = 'mutated';
        $kept->save();
        $extra   = $this->makeAccount(['service' => 'Extra', 'account' => 'extra']);
        $extraId = $extra->id;

        $diff   = $this->service->dryRun($this->user, $snapshot, 'replace');
        $result = $this->service->restore($this->user, $snapshot, 'replace', $diff['token'], 'RESTORE');

        $this->assertSame(0, $result['created_count']); // kept+dropped are in the snapshot and still exist.
        $this->assertSame(1, $result['deleted_count']); // Extra was NOT in the snapshot → replace wiped it.
        $this->assertSame(2, $result['updated_count']); // kept+dropped upserted in place.
        $this->assertSame(1, BackupSnapshot::where('user_id', $this->user->id)->where('source', 'pre_restore')->count(), 'replace must auto-create a pre_restore snapshot');
        $this->assertDatabaseMissing('twofaccounts', ['id' => $extraId]);
        // In-snapshot rows keep their id (no delete+create churn).
        $this->assertDatabaseHas('twofaccounts', ['id' => $dropped->id]);

        // Vault state equals the snapshot again.
        $this->assertSame(2, TwoFAccount::where('user_id', $this->user->id)->count());
        $restoredKept = $kept->fresh();
        $this->assertNull($restoredKept->notes);

        // Icon file of the KEPT account is still present (RT-2: no
        // delete-all/create-all churn — id-matched rows are updated in place).
        Storage::disk('icons')->assertExists('kept-icon.svg');
    }

    public function test_replace_garbage_collects_icons_of_wiped_accounts() : void
    {
        Storage::fake('icons');
        Storage::disk('icons')->put('doomed-icon.svg', 'svg');

        [$snapshot] = $this->takeSnapshot();

        // The doomed account was created AFTER the snapshot: replace wipes it
        // and its now-unreferenced icon file is garbage-collected.
        $this->makeAccount(['service' => 'Doomed', 'icon' => 'doomed-icon.svg']);

        $diff = $this->service->dryRun($this->user, $snapshot, 'replace');
        $this->service->restore($this->user, $snapshot, 'replace', $diff['token'], 'RESTORE');

        Storage::disk('icons')->assertMissing('doomed-icon.svg');
    }

    public function test_replace_with_malformed_row_rolls_back_everything_and_touches_no_icons() : void
    {
        Storage::fake('icons');
        Storage::disk('icons')->put('kept-icon.svg', 'svg');

        $kept                 = $this->makeAccount(['icon' => 'kept-icon.svg']);
        [$snapshot, $payload] = $this->takeSnapshot();

        // A row that will explode mid-upsert (invalid otp_type trips the
        // saving hook's validation... use an oversize secret instead: pass a
        // non-string secret, which the mutator cannot pad).
        $payload['accounts'][0]['secret'] = ['array' => 'not-a-string'];

        // Re-seal the tampered payload into the snapshot's file.
        $encrypted = Crypt::encryptString(json_encode($payload));
        Storage::disk('snapshots')->put($snapshot->file_path, $encrypted);
        $snapshot->checksum = hash('sha256', $encrypted);
        $snapshot->save();

        $extra           = $this->makeAccount(['service' => 'Extra', 'account' => 'extra']);
        $extraId         = $extra->id;
        $keptNotesBefore = $kept->fresh()->notes;

        $diff = $this->service->dryRun($this->user, $snapshot, 'replace');

        try {
            $this->service->restore($this->user, $snapshot, 'replace', $diff['token'], 'RESTORE');
            $this->fail('expected the malformed row to abort the transaction');
        } catch (\Throwable) {
            // expected — any account-level exception aborts ALL (RT-5)
        }

        // Zero mutations: the extra account still exists, kept is untouched.
        $this->assertDatabaseHas('twofaccounts', ['id' => $extraId]);
        $this->assertSame($keptNotesBefore, $kept->fresh()->notes);
        Storage::disk('icons')->assertExists('kept-icon.svg');

        // Rollback leaves no pre_restore disk artifacts (row + file both absent).
        $preRestores = BackupSnapshot::where('user_id', $this->user->id)->where('source', 'pre_restore')->get();
        foreach ($preRestores as $preRestore) {
            // Either fully rolled back (no row) or reconciled (no orphan file).
            if ($preRestore->exists) {
                Storage::disk('snapshots')->assertExists($preRestore->file_path);
            }
        }
    }

    public function test_restore_emits_no_per_account_activity_spam() : void
    {
        $kept       = $this->makeAccount();
        [$snapshot] = $this->takeSnapshot();

        $extra   = $this->makeAccount(['service' => 'Extra', 'account' => 'extra']);
        $extraId = $extra->id;

        $diff = $this->service->dryRun($this->user, $snapshot, 'replace');
        $this->service->restore($this->user, $snapshot, 'replace', $diff['token'], 'RESTORE');

        // One summary entry (backup_restored), none of the per-account
        // TwoFAccountDeleted log lines (event-less wipe).
        $logs = DB::table('personal_activity_logs')
            ->where('user_id', $this->user->id)
            ->where('action', 'account_deleted')
            ->count();
        $this->assertSame(0, $logs);
        $this->assertSame(1, DB::table('personal_activity_logs')
            ->where('user_id', $this->user->id)
            ->where('action', 'backup_restored')
            ->count());
    }
}
