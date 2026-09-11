<?php

namespace Tests\Api\v1\Controllers;

use App\Models\SharedAccount;
use App\Models\Team;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Secret-leakage regression tests (leak audit 2026-09-12, upstream 088aa0583
 * analog): a shared-account member holds only the `view` ability (server-side
 * OTP generation). They must never receive the account secret — plaintext for
 * non-E2EE accounts, the encrypted_secret blob otherwise — even when they
 * explicitly request withSecret=1. The owner must keep receiving it.
 */
class SharedAccountSecretLeakTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $member;

    protected TwoFAccount $sharedPlainAccount;

    protected TwoFAccount $sharedEncryptedAccount;

    protected function setUp() : void
    {
        parent::setUp();

        $createUser = fn () => User::factory()->create([
            'encryption_enabled'    => true,
            'encryption_salt'       => 'test_salt',
            'encryption_test_value' => '{"ciphertext":"test","iv":"test","authTag":"test"}',
            'encryption_version'    => 1,
            'vault_locked'          => false,
        ]);

        $this->owner  = $createUser();
        $this->member = $createUser();

        $team = Team::create([
            'name'        => 'Leak Audit Team',
            'owner_id'    => $this->owner->id,
            'invite_code' => \Illuminate\Support\Str::random(16),
        ]);
        $team->users()->attach($this->owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $team->users()->attach($this->member->id, ['role' => 'member', 'joined_at' => now()]);

        $this->sharedPlainAccount = TwoFAccount::factory()->for($this->owner)->create([
            'secret'    => 'A4GRFHVVRB7Q4PVD',
            'encrypted' => false,
        ]);

        $encryptedSecret = json_encode([
            'ciphertext' => base64_encode('client_encrypted_secret'),
            'iv'         => base64_encode(random_bytes(12)),
            'authTag'    => base64_encode(random_bytes(16)),
        ]);

        $this->sharedEncryptedAccount = TwoFAccount::factory()->for($this->owner)->create([
            'secret'    => $encryptedSecret,
            'encrypted' => true,
        ]);

        foreach ([$this->sharedPlainAccount, $this->sharedEncryptedAccount] as $account) {
            SharedAccount::create([
                'team_id'        => $team->id,
                'twofaccount_id' => $account->id,
                'shared_by'      => $this->owner->id,
                'member_id'      => $this->member->id,
                'access_level'   => 'read',
                'wrapped_key'    => 'wrapped-for-member',
            ]);
        }
    }

    public function test_show_does_not_return_secret_to_shared_member_even_when_requested() : void
    {
        Passport::actingAs($this->member, ['legacy_full_access'], 'api-guard');

        foreach ([$this->sharedPlainAccount, $this->sharedEncryptedAccount] as $account) {
            $response = $this->json('GET', '/api/v1/twofaccounts/' . $account->id . '?withSecret=1')
                ->assertOk();

            $this->assertArrayNotHasKey('secret', $response->json(), 'secret leaked to shared member for account ' . $account->id);
        }
    }

    public function test_show_still_returns_secret_to_owner() : void
    {
        Passport::actingAs($this->owner, ['legacy_full_access'], 'api-guard');

        $this
            ->json('GET', '/api/v1/twofaccounts/' . $this->sharedPlainAccount->id . '?withSecret=1')
            ->assertOk()
            ->assertJsonPath('secret', 'A4GRFHVVRB7Q4PVD');

        $this
            ->json('GET', '/api/v1/twofaccounts/' . $this->sharedEncryptedAccount->id . '?withSecret=1')
            ->assertOk()
            ->assertJsonPath('secret', $this->sharedEncryptedAccount->secret);
    }

    public function test_shared_with_me_index_does_not_return_secrets_even_when_requested() : void
    {
        Passport::actingAs($this->member, ['legacy_full_access'], 'api-guard');

        $response = $this
            ->json('GET', '/api/v1/twofaccounts?group_id=-4&withSecret=1')
            ->assertOk();

        $this->assertCount(2, $response->json());

        foreach ($response->json() as $account) {
            $this->assertArrayNotHasKey('secret', $account);
        }
    }

    public function test_own_index_still_returns_secrets_to_owner_when_requested() : void
    {
        Passport::actingAs($this->owner, ['legacy_full_access'], 'api-guard');

        $response = $this
            ->json('GET', '/api/v1/twofaccounts?withSecret=1')
            ->assertOk();

        $secrets = collect($response->json())->pluck('secret')->filter()->values();

        $this->assertContains('A4GRFHVVRB7Q4PVD', $secrets);
        $this->assertContains($this->sharedEncryptedAccount->secret, $secrets);
    }

    public function test_export_is_forbidden_for_shared_member() : void
    {
        Passport::actingAs($this->member, ['legacy_full_access'], 'api-guard');

        $this
            ->json('GET', '/api/v1/twofaccounts/export?ids=' . $this->sharedPlainAccount->id . ',' . $this->sharedEncryptedAccount->id)
            ->assertForbidden();
    }

    public function test_otp_generation_still_allowed_for_shared_member() : void
    {
        Passport::actingAs($this->member, ['legacy_full_access'], 'api-guard');

        $this
            ->json('GET', '/api/v1/twofaccounts/' . $this->sharedPlainAccount->id . '/otp')
            ->assertOk()
            ->assertJsonStructure(['password']);
    }

    public function test_non_member_cannot_view_shared_account() : void
    {
        $outsider = User::factory()->create();

        Passport::actingAs($outsider, ['legacy_full_access'], 'api-guard');

        $this
            ->json('GET', '/api/v1/twofaccounts/' . $this->sharedPlainAccount->id)
            ->assertForbidden();
    }
}
