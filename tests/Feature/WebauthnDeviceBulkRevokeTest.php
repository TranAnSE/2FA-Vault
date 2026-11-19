<?php

namespace Tests\Feature;

use App\Facades\Settings;
use App\Models\PasskeyPrfCredential;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;
use Tests\Support\PasskeyAssertionFactory;

/**
 * Bulk revocation of security devices (plan 261119-1650 phase 5).
 *
 * The web-family batch endpoint loops the per-device delete semantics:
 * per-id result diffing (foreign ids surface as `unknown`, never a silent
 * no-op), the PRF-enrollment FK cascade, and the #110 useWebauthnOnly
 * reset when the batch empties the device list (red-team P1-D — dropping
 * it would lock a webauthn-only user out of their account).
 */
class WebauthnDeviceBulkRevokeTest extends FeatureTestCase
{
    protected User $user;

    protected function setUp() : void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * Seed a PRF enrollment row for the given credential id.
     */
    protected function seedEnrollmentFor(User $user, string $credentialId) : PasskeyPrfCredential
    {
        $passkey = $user->passkeys()->where('credential_id', $credentialId)->first();

        $enrollment                      = new PasskeyPrfCredential;
        $enrollment->user_id             = $user->id;
        $enrollment->passkey_id          = $passkey->id;
        $enrollment->public_key          = str_repeat('A', 100);
        $enrollment->wrapped_private_key = str_repeat('B', 100);
        $enrollment->wrapped_vault_key   = str_repeat('C', 100);
        $enrollment->save();

        return $enrollment;
    }

    protected function bulkRevoke(User $user, array $ids)
    {
        return $this->actingAs($user, 'web-guard')
            ->postJson('/webauthn/credentials/bulk-revoke', ['ids' => $ids]);
    }

    #[Test]
    public function test_bulk_revoke_requires_an_authenticated_session()
    {
        $this->postJson('/webauthn/credentials/bulk-revoke', ['ids' => ['x']])->assertStatus(401);
    }

    #[Test]
    public function test_bulk_revoke_denies_sso_only_users()
    {
        Settings::set('useSsoOnly', true);

        $this->bulkRevoke($this->user, ['x'])->assertStatus(403);
    }

    #[Test]
    public function test_bulk_revoke_validates_the_payload()
    {
        // empty selection
        $this->bulkRevoke($this->user, [])->assertStatus(422);

        // missing ids
        $this->actingAs($this->user, 'web-guard')
            ->postJson('/webauthn/credentials/bulk-revoke', [])->assertStatus(422);
    }

    #[Test]
    public function test_bulk_revoke_removes_only_the_selected_devices_and_cascades_enrollments()
    {
        $keep    = PasskeyAssertionFactory::convertedCredentialId($this->user);
        $revokeA = PasskeyAssertionFactory::convertedCredentialId($this->user);
        $revokeB = PasskeyAssertionFactory::convertedCredentialId($this->user);

        $enrollmentA = $this->seedEnrollmentFor($this->user, $revokeA);
        $enrollmentB = $this->seedEnrollmentFor($this->user, $revokeB);

        $this->bulkRevoke($this->user, [$revokeA, $revokeB])
            ->assertStatus(200)
            ->assertExactJson([
                'deleted' => [$revokeA, $revokeB],
                'unknown' => [],
            ]);

        // Revoked devices and their PRF enrollments are gone (FK cascade)…
        $this->assertDatabaseMissing('passkeys', ['credential_id' => $revokeA]);
        $this->assertDatabaseMissing('passkeys', ['credential_id' => $revokeB]);
        $this->assertDatabaseMissing('passkey_prf_credentials', ['id' => $enrollmentA->id]);
        $this->assertDatabaseMissing('passkey_prf_credentials', ['id' => $enrollmentB->id]);

        // …while the unselected device survives intact (it carries no PRF
        // enrollment — only A/B were enrolled).
        $this->assertDatabaseHas('passkeys', ['credential_id' => $keep]);
        $this->assertSame(0, PasskeyPrfCredential::count());
    }

    #[Test]
    public function test_revoke_all_resets_usewebauthnonly_to_prevent_lockout()
    {
        // The #110 trap: a webauthn-only user who revokes the last device
        // must not be left with an impossible login preference.
        $this->user['preferences->useWebauthnOnly'] = true;
        $this->user->save();

        $first  = PasskeyAssertionFactory::convertedCredentialId($this->user);
        $second = PasskeyAssertionFactory::convertedCredentialId($this->user);

        $this->bulkRevoke($this->user, [$first, $second])->assertStatus(200);

        $this->assertSame(0, $this->user->passkeys()->count());
        $this->assertFalse($this->user->fresh()->preferences['useWebauthnOnly']);
    }

    #[Test]
    public function test_bulk_revoke_keeps_partially_kept_usewebauthnonly()
    {
        // Non-emptying batch must NOT touch the preference.
        $this->user['preferences->useWebauthnOnly'] = true;
        $this->user->save();

        $keep   = PasskeyAssertionFactory::convertedCredentialId($this->user);
        $revoke = PasskeyAssertionFactory::convertedCredentialId($this->user);

        $this->bulkRevoke($this->user, [$revoke])->assertStatus(200);

        $this->assertTrue($this->user->fresh()->preferences['useWebauthnOnly']);
        $this->assertDatabaseHas('passkeys', ['credential_id' => $keep]);
    }

    #[Test]
    public function test_foreign_ids_surface_as_unknown_and_owned_devices_stay_untouched()
    {
        $mine    = PasskeyAssertionFactory::convertedCredentialId($this->user);
        $foreign = PasskeyAssertionFactory::convertedCredentialId(User::factory()->create());

        $this->bulkRevoke($this->user, [$mine, $foreign])
            ->assertStatus(200)
            ->assertExactJson([
                'deleted' => [$mine],
                'unknown' => [$foreign],
            ]);

        // The foreign device (and its enrollment if any) is untouched.
        $this->assertDatabaseHas('passkeys', ['credential_id' => $foreign]);
        $this->assertDatabaseMissing('passkeys', ['credential_id' => $mine]);
    }
}
