<?php

namespace Tests\Feature;

use App\Facades\Settings;
use App\Models\PasskeyPrfCredential;
use App\Models\User;
use App\Services\EncryptionService;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;
use Tests\Support\PasskeyAssertionFactory;
use Tests\Support\PasskeyCredentialHandle;

/**
 * PRF vault-unlock API tests (plan 261114-2101 phase 2).
 *
 * Covers the possession-proof enrollment contract (red-team Critical F1),
 * the atomic lazy salt, the stale-writer rewrap guard, ownership scoping,
 * and the E2EE lifecycle wipes (red-team F8).
 */
class PrfUnlockTest extends FeatureTestCase
{
    protected User $user;

    // Recovery-token pair from WebAuthnRecoveryControllerTest (bcrypt pair).
    protected const RECOVERY_STORED_TOKEN = '$2y$10$P6q8rl8te5QaO1EdpyJcNO0s9VFlVgf62KaItQhrPTskxfyu97mlW';

    protected const RECOVERY_ACTUAL_TOKEN = '9e583e3fb6c32034164ac62415c9657dcbd1fb861b434340b08a94c2075cac66';

    protected function setUp() : void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    // --------------------------------------------------------------- helpers

    /**
     * Flip the user into the E2EE-enabled state (family test pattern).
     */
    protected function enableE2ee(?User $user = null) : User
    {
        $user ??= $this->user;

        $user->forceFill([
            'encryption_enabled'    => true,
            'encryption_salt'       => 'e2ee_salt',
            'encryption_test_value' => '{"ciphertext":"t","iv":"t","authTag":"t"}',
            'encryption_version'    => 1,
        ])->save();

        return $user;
    }

    /**
     * Seed a PRF enrollment row directly (no ceremony) for lifecycle tests.
     */
    protected function seedEnrollment(User $user) : PasskeyPrfCredential
    {
        $credentialId = PasskeyAssertionFactory::convertedCredentialId($user);

        $passkey = $user->passkeys()->where('credential_id', $credentialId)->first();

        // Explicit key assignment (the FK pair is not mass-assignable).
        $enrollment                      = new PasskeyPrfCredential;
        $enrollment->user_id             = $user->id;
        $enrollment->passkey_id          = $passkey->id;
        $enrollment->public_key          = str_repeat('A', 100);
        $enrollment->wrapped_private_key = str_repeat('B', 100);
        $enrollment->wrapped_vault_key   = str_repeat('C', 100);
        $enrollment->save();

        return $enrollment;
    }

    /**
     * Drive the real enroll ceremony: options -> signed assertion -> enroll.
     */
    protected function enrollThroughCeremony(User $user, PasskeyCredentialHandle $handle, array $blobs = []) : TestResponse
    {
        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');

        $challenge = $this->postJson('/api/v1/encryption/prf/enroll/options', [
            'credential_id' => $handle->credentialId,
        ])->json('challenge');

        $assertion = $handle->assertion($challenge);

        return $this->postJson('/api/v1/encryption/prf/enroll', array_merge([
            'credential_id'       => $handle->credentialId,
            'assertion'           => $assertion['credential'],
            'public_key'          => str_repeat('P', 100),
            'wrapped_private_key' => str_repeat('W', 100),
            'wrapped_vault_key'   => str_repeat('V', 100),
        ], $blobs));
    }

    // --------------------------------------------------------------- context

    #[Test]
    public function test_context_returns_salt_encryption_fields_and_empty_enrollments()
    {
        $this->enableE2ee();
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $this->getJson('/api/v1/encryption/prf/context')
            ->assertOk()
            ->assertJsonStructure(['prf_salt', 'encryption_salt', 'encryption_test_value', 'enrollments']);

        $this->assertNotNull($this->user->fresh()->prf_salt);
        $this->assertSame('e2ee_salt', $this->user->fresh()->encryption_salt);
    }

    #[Test]
    public function test_context_repeated_reads_converge_on_one_salt()
    {
        $this->enableE2ee();
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $first  = $this->getJson('/api/v1/encryption/prf/context')->json('prf_salt');
        $second = $this->getJson('/api/v1/encryption/prf/context')->json('prf_salt');

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
    }

    #[Test]
    public function test_context_atomic_first_touch_wins_over_stale_writer()
    {
        // Simulates the two-tab split-brain: a stale writer races the atomic
        // guarded UPDATE and must NOT overwrite the created salt (red-team M10).
        $this->enableE2ee();
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $salt = $this->getJson('/api/v1/encryption/prf/context')->json('prf_salt');

        // The guarded UPDATE only fires when prf_salt IS NULL: replaying it
        // (as a racer that first-read before salt creation would) is a no-op.
        $changed = User::whereKey($this->user->getKey())
            ->whereNull('prf_salt')
            ->update(['prf_salt' => 'stale-writer-salt']);

        $this->assertSame(0, $changed);
        $this->getJson('/api/v1/encryption/prf/context')->assertOk();
        $this->assertSame($salt, $this->user->fresh()->prf_salt);
    }

    #[Test]
    public function test_context_does_not_leak_other_users_data()
    {
        $this->enableE2ee();
        $other = User::factory()->create();
        $other->forceFill([
            'encryption_enabled'    => true,
            'encryption_salt'       => 'other-user-secret-salt',
            'encryption_test_value' => '{"ciphertext":"other","iv":"o","authTag":"o"}',
            'encryption_version'    => 1,
        ])->save();
        $this->seedEnrollment($other);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $body = $this->getJson('/api/v1/encryption/prf/context')->json();

        $this->assertCount(0, $body['enrollments']);
        $this->assertSame('e2ee_salt', $body['encryption_salt']);
        $this->assertStringNotContainsString('other-user-secret-salt', json_encode($body));
        $this->assertStringNotContainsString('other-user', json_encode($body));
    }

    // ------------------------------------------------- E2EE-disabled guard

    #[Test]
    public function test_all_five_endpoints_reject_users_without_e2ee()
    {
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $this->getJson('/api/v1/encryption/prf/context')->assertStatus(400);

        $this->postJson('/api/v1/encryption/prf/enroll/options', ['credential_id' => 'x'])->assertStatus(400);

        // Valid-shaped payload: FormRequest validation must pass so the
        // controller's E2EE guard is what produces the 400.
        $this->postJson('/api/v1/encryption/prf/enroll', $this->validEnrollPayload())->assertStatus(400);

        $this->postJson('/api/v1/encryption/prf/rewrap', [
            'expected_encryption_salt_hash' => str_repeat('0', 64),
            'wrappings'                     => [['credential_id' => 'x', 'wrapped_vault_key' => 'v']],
        ])->assertStatus(400);

        $this->deleteJson('/api/v1/encryption/prf/some-credential')->assertStatus(400);
    }

    /**
     * A validation-valid enroll payload (b64url-shaped assertion, blobs
     * within limits) for tests targeting the guards rather than validation.
     */
    protected function validEnrollPayload(string $credentialId = 'x') : array
    {
        return [
            'credential_id' => $credentialId,
            'assertion'     => [
                'id'       => str_repeat('q', 43),
                'rawId'    => str_repeat('q', 43),
                'type'     => 'public-key',
                'response' => [
                    'clientDataJSON'    => str_repeat('c', 43),
                    'authenticatorData' => str_repeat('a', 43),
                    'signature'         => str_repeat('s', 43),
                ],
            ],
            'public_key'          => str_repeat('P', 100),
            'wrapped_private_key' => str_repeat('W', 100),
            'wrapped_vault_key'   => str_repeat('V', 100),
        ];
    }

    // ---------------------------------------------------------- enroll flow

    #[Test]
    public function test_enroll_options_serve_a_single_credential_challenge()
    {
        $this->enableE2ee();
        $handle = PasskeyAssertionFactory::convertedCredential($this->user);
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $body = $this->postJson('/api/v1/encryption/prf/enroll/options', [
            'credential_id' => $handle->credentialId,
        ])->assertOk()->json();

        $this->assertNotEmpty($body['challenge']);
        $this->assertCount(1, $body['allowCredentials']);
        $this->assertSame($handle->credentialId, $body['allowCredentials'][0]['id']);
    }

    #[Test]
    public function test_enroll_options_reject_a_foreign_credential()
    {
        $this->enableE2ee();
        $other     = $this->enableE2ee(User::factory()->create());
        $foreignId = PasskeyAssertionFactory::convertedCredentialId($other);
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $this->postJson('/api/v1/encryption/prf/enroll/options', [
            'credential_id' => $foreignId,
        ])->assertNotFound();
    }

    #[Test]
    public function test_enroll_options_expire_after_the_ttl_gate()
    {
        $this->enableE2ee();
        $handle = PasskeyAssertionFactory::convertedCredential($this->user);
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $this->postJson('/api/v1/encryption/prf/enroll/options', ['credential_id' => $handle->credentialId]);

        // Expire the stored challenge (single-use cache entry, M2 semantics).
        Cache::put('prf_enroll_challenge_' . $this->user->id, 'expired', now()->subSecond());

        $this->postJson('/api/v1/encryption/prf/enroll', [
            'credential_id'       => $handle->credentialId,
            'assertion'           => $handle->assertion('stale-challenge')['credential'],
            'public_key'          => str_repeat('P', 100),
            'wrapped_private_key' => str_repeat('W', 100),
            'wrapped_vault_key'   => str_repeat('V', 100),
        ])->assertStatus(401);
    }

    #[Test]
    public function test_enroll_stores_blobs_after_a_verified_assertion()
    {
        $this->enableE2ee();
        $handle = PasskeyAssertionFactory::convertedCredential($this->user);

        $this->enrollThroughCeremony($this->user, $handle)->assertNoContent();

        $this->assertSame(1, PasskeyPrfCredential::count());
        $enrollment = PasskeyPrfCredential::first();
        $this->assertSame($handle->credentialId, $enrollment->passkey->credential_id);
        $this->assertSame(str_repeat('P', 100), $enrollment->public_key);

        // Possession proof must not log anyone in (D2): the enroll endpoint
        // leaves no web-guard session login behind.
        $this->assertFalse(auth('web')->check());
    }

    #[Test]
    public function test_enroll_rejects_an_assertion_signed_by_a_wrong_key()
    {
        $this->enableE2ee();
        $handle = PasskeyAssertionFactory::convertedCredential($this->user);
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $challenge = $this->postJson('/api/v1/encryption/prf/enroll/options', [
            'credential_id' => $handle->credentialId,
        ])->json('challenge');

        $forged = $handle->wrongKeyAssertion($challenge);

        $this->postJson('/api/v1/encryption/prf/enroll', [
            'credential_id'       => $handle->credentialId,
            'assertion'           => $forged['credential'],
            'public_key'          => str_repeat('P', 100),
            'wrapped_private_key' => str_repeat('W', 100),
            'wrapped_vault_key'   => str_repeat('V', 100),
        ])->assertStatus(422);

        // Nothing stored — the exfiltration-oracle path (red-team F1) is dead.
        $this->assertSame(0, PasskeyPrfCredential::count());
    }

    #[Test]
    public function test_enroll_rejects_a_foreign_credential()
    {
        $this->enableE2ee();
        $other   = $this->enableE2ee(User::factory()->create());
        $foreign = PasskeyAssertionFactory::convertedCredential($other);
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        // Options for the foreign credential are already 404; a direct enroll
        // attempt against it must 404 before any verification work.
        $this->postJson('/api/v1/encryption/prf/enroll', [
            'credential_id'       => $foreign->credentialId,
            'assertion'           => $foreign->assertion('any-challenge')['credential'],
            'public_key'          => str_repeat('P', 100),
            'wrapped_private_key' => str_repeat('W', 100),
            'wrapped_vault_key'   => str_repeat('V', 100),
        ])->assertNotFound();

        $this->assertSame(0, PasskeyPrfCredential::count());
    }

    #[Test]
    public function test_reenroll_replaces_blobs_without_growing_the_row_set()
    {
        $this->enableE2ee();
        $handle = PasskeyAssertionFactory::convertedCredential($this->user);

        $this->enrollThroughCeremony($this->user, $handle)->assertNoContent();
        $this->enrollThroughCeremony($this->user, $handle, [
            'public_key'          => str_repeat('X', 100),
            'wrapped_private_key' => str_repeat('Y', 100),
            'wrapped_vault_key'   => str_repeat('Z', 100),
        ])->assertNoContent();

        $this->assertSame(1, PasskeyPrfCredential::count());
        $this->assertSame(str_repeat('X', 100), PasskeyPrfCredential::first()->public_key);
    }

    // ---------------------------------------------------------------- rewrap

    #[Test]
    public function test_rewrap_updates_only_the_wrapped_vault_key()
    {
        $this->enableE2ee();
        $enrollment = $this->seedEnrollment($this->user);
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $saltHash = hash('sha256', 'e2ee_salt');

        $this->postJson('/api/v1/encryption/prf/rewrap', [
            'expected_encryption_salt_hash' => $saltHash,
            'wrappings'                     => [
                ['credential_id' => $enrollment->passkey->credential_id, 'wrapped_vault_key' => 'new-wrapped-k'],
            ],
        ])->assertNoContent();

        $enrollment->refresh();
        $this->assertSame('new-wrapped-k', $enrollment->wrapped_vault_key);
        $this->assertSame(str_repeat('A', 100), $enrollment->public_key);
        $this->assertSame(str_repeat('B', 100), $enrollment->wrapped_private_key);
    }

    #[Test]
    public function test_rewrap_rejects_a_stale_salt_hash_with_409()
    {
        $this->enableE2ee();
        $enrollment = $this->seedEnrollment($this->user);
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $this->postJson('/api/v1/encryption/prf/rewrap', [
            'expected_encryption_salt_hash' => str_repeat('0', 64),
            'wrappings'                     => [
                ['credential_id' => $enrollment->passkey->credential_id, 'wrapped_vault_key' => 'evil'],
            ],
        ])->assertStatus(409);

        $enrollment->refresh();
        $this->assertSame(str_repeat('C', 100), $enrollment->wrapped_vault_key);
    }

    #[Test]
    public function test_rewrap_rejects_unknown_credential_ids_without_touching_rows()
    {
        $this->enableE2ee();
        $enrollment = $this->seedEnrollment($this->user);
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $this->postJson('/api/v1/encryption/prf/rewrap', [
            'expected_encryption_salt_hash' => hash('sha256', 'e2ee_salt'),
            'wrappings'                     => [
                ['credential_id' => $enrollment->passkey->credential_id, 'wrapped_vault_key' => 'good'],
                ['credential_id' => 'not-mine', 'wrapped_vault_key' => 'bad'],
            ],
        ])->assertStatus(422);

        $enrollment->refresh();
        $this->assertSame(str_repeat('C', 100), $enrollment->wrapped_vault_key);
    }

    #[Test]
    public function test_rewrap_allows_up_to_100_wrappings()
    {
        $this->enableE2ee();
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        // 100 owned enrollments is the documented ceiling.
        for ($i = 0; $i < 100; $i++) {
            $this->seedEnrollment($this->user);
        }

        $wrappings = PasskeyPrfCredential::with('passkey')->get()
            ->map(fn ($e) => ['credential_id' => $e->passkey->credential_id, 'wrapped_vault_key' => 'k' . $e->id])
            ->all();

        $this->assertCount(100, $wrappings);

        $this->postJson('/api/v1/encryption/prf/rewrap', [
            'expected_encryption_salt_hash' => hash('sha256', 'e2ee_salt'),
            'wrappings'                     => $wrappings,
        ])->assertNoContent();
    }

    // ----------------------------------------------------------- delete flow

    #[Test]
    public function test_delete_removes_only_the_own_enrollment()
    {
        $this->enableE2ee();
        $mine    = $this->seedEnrollment($this->user);
        $other   = $this->enableE2ee(User::factory()->create());
        $foreign = $this->seedEnrollment($other);
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $this->deleteJson('/api/v1/encryption/prf/' . $foreign->passkey->credential_id)->assertNotFound();
        $this->deleteJson('/api/v1/encryption/prf/' . $mine->passkey->credential_id)->assertNoContent();

        $this->assertSame(1, PasskeyPrfCredential::count());
    }

    #[Test]
    public function test_passkey_revocation_cascades_the_enrollment()
    {
        $this->enableE2ee();
        $enrollment = $this->seedEnrollment($this->user);

        // flushCredential = the revoke path hard-deletes the passkey row;
        // the FK cascade must take the PRF enrollment with it (D6).
        $this->user->flushCredential($enrollment->passkey->credential_id);

        $this->assertSame(0, PasskeyPrfCredential::count());
    }

    #[Test]
    public function test_user_deletion_cascades_the_enrollment()
    {
        $this->enableE2ee();
        $this->seedEnrollment($this->user);

        $this->user->delete();

        $this->assertSame(0, PasskeyPrfCredential::count());
    }

    // ------------------------------------------------------- lifecycle wipes

    #[Test]
    public function test_encryption_disable_wipes_enrollments_and_salt()
    {
        $this->enableE2ee();
        $this->seedEnrollment($this->user);
        $this->user->forceFill(['password' => Hash::make('OldPassw0rd!')])->save();
        $this->user->refresh()->update(['prf_salt' => 'salt-to-die']);
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $this->deleteJson('/api/v1/encryption/disable', [
            'password' => 'OldPassw0rd!',
            'confirm'  => true,
        ])->assertOk();

        $this->user->refresh();
        $this->assertSame(0, PasskeyPrfCredential::count());
        $this->assertNull($this->user->prf_salt);
    }

    #[Test]
    public function test_encryption_setup_flushes_any_stale_unlock_state()
    {
        // A re-setup after disable must leave zero stale enrollments (D6).
        $this->enableE2ee();
        $this->seedEnrollment($this->user);
        $this->user->update(['prf_salt' => 'old-salt']);

        $service = app(EncryptionService::class);
        $service->disableEncryption($this->user);
        $service->setupEncryption($this->user, 'new-salt', '{"ciphertext":"n"}', 1);

        $this->assertSame(0, PasskeyPrfCredential::count());
        $this->assertNull($this->user->fresh()->prf_salt);
    }

    // -------------------------------------------------------------- SSO-only

    #[Test]
    public function test_sso_only_instances_deny_all_five_endpoints()
    {
        $this->enableE2ee();
        $this->seedEnrollment($this->user);
        Settings::set('useSsoOnly', true);
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $this->getJson('/api/v1/encryption/prf/context')->assertStatus(403);
        $this->postJson('/api/v1/encryption/prf/enroll/options', ['credential_id' => 'x'])->assertStatus(403);
        $this->postJson('/api/v1/encryption/prf/enroll', $this->validEnrollPayload())->assertStatus(403);
        $this->postJson('/api/v1/encryption/prf/rewrap', [
            'expected_encryption_salt_hash' => str_repeat('0', 64),
            'wrappings'                     => [['credential_id' => 'x', 'wrapped_vault_key' => 'v']],
        ])->assertStatus(403);
        $this->deleteJson('/api/v1/encryption/prf/x')->assertStatus(403);
    }

    // ------------------------------------------------------- passkey recovery

    /**
     * The device-lost recovery flow (email token + revokeAll) flushes every
     * passkey; the FK cascade must take the PRF enrollments with it so no
     * stale unlock blob outlives its credential (D6).
     */
    #[Test]
    public function test_account_recovery_flushes_prf_enrollments()
    {
        $this->enableE2ee();
        PasskeyAssertionFactory::convertedCredentialId($this->user);
        $this->seedEnrollment($this->user);
        $this->user->update(['prf_salt' => 'recover-salt']);

        Date::setTestNow(Date::create(2026, 11, 15, 9, 4));

        DB::table(config('auth.passwords.webauthn.table'))->insert([
            'email'      => $this->user->email,
            'token'      => self::RECOVERY_STORED_TOKEN,
            'created_at' => now()->toDateTimeString(),
        ]);

        $this->postJson('/webauthn/recover', [
            'token'     => self::RECOVERY_ACTUAL_TOKEN,
            'email'     => $this->user->email,
            'password'  => UserFactory::USER_PASSWORD,
            'revokeAll' => true,
        ])->assertStatus(200);

        $this->assertDatabaseMissing('passkeys', ['user_id' => $this->user->id]);
        $this->assertSame(0, PasskeyPrfCredential::count());
    }
}
