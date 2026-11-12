<?php

namespace Tests\Feature\Http\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;
use Tests\Support\PasskeyAssertionFactory;
use Tests\Support\PasskeyAttestationFactory;

/**
 * WebAuthn (passkey) registration contract tests (plan 261009): the options
 * endpoint runs GenerateRegistrationOptions for real and the register
 * endpoint stores through StorePasskey with a synthetic attestation whose
 * COSE key, origin, challenge and UV flag are genuinely verified by
 * webauthn-lib.
 */
#[CoversClass(WebAuthnRegisterController::class)]
class WebAuthnRegisterControllerTest extends FeatureTestCase
{
    protected $user;

    protected function setUp() : void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * D7 (plan 261009): the former config('webauthn.user_verification')
     * switch was dead and is not reproduced -- registration options ALWAYS
     * request user verification.
     */
    #[Test]
    public function test_register_options_always_emit_required_user_verification() : void
    {
        Config::set('webauthn.user_verification', 'discouraged');

        $this->actingAs($this->user, 'web-guard')
            ->json('POST', '/webauthn/register/options')
            ->assertOk()
            ->assertJsonFragment([
                'userVerification' => 'required',
            ]);
    }

    #[Test]
    public function test_register_options_returns_challenge_for_authenticated_user() : void
    {
        $this->actingAs($this->user, 'web-guard')
            ->json('POST', '/webauthn/register/options')
            ->assertOk()
            ->assertJsonStructure([
                'challenge',
                'rp',
                'pubKeyCredParams',
                'authenticatorSelection' => [
                    'userVerification',
                    'residentKey',
                ],
            ]);
    }

    /**
     * Full registration ceremony: options -> synthetic attestation -> 204 +
     * stored passkey row.
     */
    #[Test]
    public function test_device_registers_a_passkey_end_to_end() : void
    {
        $this->actingAs($this->user, 'web-guard');

        $challenge = $this->json('POST', '/webauthn/register/options')->json('challenge');

        $attestation = PasskeyAttestationFactory::create($challenge);

        $this->json('POST', '/webauthn/register', $attestation + ['name' => 'My device'])
            ->assertNoContent();

        $this->assertDatabaseHas('passkeys', [
            'user_id' => $this->user->id,
            'name' => 'My device',
        ]);
    }

    #[Test]
    public function test_register_without_options_in_session_fails() : void
    {
        // No /register/options ran before: the ceremony is rejected, not a 500.
        $this->actingAs($this->user, 'web-guard')
            ->json('POST', '/webauthn/register', ['name' => 'x'])
            ->assertStatus(422);
    }
}
