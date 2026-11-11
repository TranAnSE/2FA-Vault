<?php

namespace Tests\Feature\Http\Auth;

use App\Models\User;
use App\Support\Passkeys\OptionsSession;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;
use Tests\Support\PasskeyAssertionFactory;
use Tests\Support\PasskeyAttestationFactory;

/**
 * Real-verification integration tests for the passkeys-backed /webauthn/*
 * contract (plan 261009-passkeys-migration phase 3 R10).
 *
 * Uses the synthetic-assertion harness (Tests\Support\PasskeyAssertionFactory):
 * every ceremony step is genuinely verified by webauthn-lib -- signature,
 * origin, rpId hash, challenge, counter and the D7
 * userVerification=required flag policy.
 */
#[CoversNothing]
class WebAuthnPasskeysIntegrationTest extends FeatureTestCase
{
    protected User $user;

    protected function setUp() : void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);
    }

    #[Test]
    public function converted_credential_logs_in_with_valid_assertion(): void
    {
        $options = $this->postJson('/webauthn/login/options', ['email' => $this->user->email]);
        $options->assertStatus(200);
        $challenge = $options->json('challenge');
        $this->assertNotNull($challenge);
        $this->assertSame('required', $options->json('userVerification'));

        $assertion = (PasskeyAssertionFactory::convertedCredential($this->user))->assertion($challenge);

        $response = $this->postJson('/webauthn/login', $assertion['credential'] + ['email' => $this->user->email]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'authenticated');
        $this->assertAuthenticatedAs($this->user);
    }

    #[Test]
    public function wrong_key_signature_is_rejected(): void
    {
        $options = $this->postJson('/webauthn/login/options', ['email' => $this->user->email]);
        $challenge = $options->json('challenge');

        $handle = PasskeyAssertionFactory::convertedCredential($this->user);

        $good = $handle->assertion($challenge);

        $bad = $handle->wrongKeyAssertion($challenge);

        $response = $this->postJson('/webauthn/login', $bad['credential'] + ['email' => $this->user->email]);

        $response->assertStatus(401);
        $this->assertGuest();
    }

    #[Test]
    public function cross_user_assertion_is_rejected(): void
    {
        $victim = User::factory()->create(['password' => Hash::make('password')]);

        $options = $this->postJson('/webauthn/login/options', ['email' => $victim->email]);
        $challenge = $options->json('challenge');

        // Attacker owns a converted credential; the request targets the victim's email.
        $assertion = (PasskeyAssertionFactory::convertedCredential($this->user))->assertion($challenge);

        $response = $this->postJson('/webauthn/login', $assertion['credential'] + ['email' => $victim->email]);

        $response->assertStatus(401);
        $this->assertGuest();
    }

    #[Test]
    public function unknown_email_gets_generic_200_shape(): void
    {
        $response = $this->postJson('/webauthn/login/options', ['email' => 'ghost@example.test']);

        $response->assertStatus(200);
        $response->assertJsonPath('message', trans('auth.failed'));
    }

    /**
     * M2 (plan 261009): the laravel/passkeys package never expires its
     * one-shot session options server-side; OptionsSession restores
     * laragear's server-side TTL gate.
     */
    #[Test]
    public function expired_session_options_reject_the_ceremony(): void
    {
        $handle = PasskeyAssertionFactory::convertedCredential($this->user);

        $challenge = $this->postJson('/webauthn/login/options', ['email' => $this->user->email])->json('challenge');

        $assertion = $handle->assertion($challenge);

        // Beyond the passkeys.timeout window (60s in the default config).
        $this->travel(120)->seconds();

        $this->postJson('/webauthn/login', $assertion['credential'] + ['email' => $this->user->email])
            ->assertStatus(401);

        $this->assertGuest();
    }

    /**
     * The same server-side TTL gate protects the registration ceremony.
     */
    #[Test]
    public function expired_registration_options_reject_the_ceremony(): void
    {
        $this->actingAs($this->user, 'web-guard');

        $challenge = $this->json('POST', '/webauthn/register/options')->json('challenge');

        $attestation = PasskeyAttestationFactory::create($challenge);

        // Simulate expiry: age the stored options beyond passkeys.timeout.
        $this->app['session.store']->put(
            OptionsSession::REGISTER_KEY.OptionsSession::AGED_KEY_SUFFIX,
            now()->timestamp - 120
        );

        $this->json('POST', '/webauthn/register', $attestation + ['name' => 'x'])
            ->assertStatus(422);
    }

    /**
     * Immediate replay (M2 sibling): the one-shot session options are pulled
     * by the first attempt, so resubmitting the same assertion fails even
     * within the TTL window.
     */
    #[Test]
    public function replayed_assertion_is_rejected_immediately(): void
    {
        $handle = PasskeyAssertionFactory::convertedCredential($this->user);

        $challenge = $this->postJson('/webauthn/login/options', ['email' => $this->user->email])->json('challenge');

        $assertion = $handle->assertion($challenge);

        $this->postJson('/webauthn/login', $assertion['credential'] + ['email' => $this->user->email])
            ->assertStatus(200);

        // Replay the SAME payload against the drained session.
        $this->postJson('/webauthn/login', $assertion['credential'] + ['email' => $this->user->email])
            ->assertStatus(401);
    }

    /**
     * SSO-only defense in depth (R2): the route middleware already returns
     * the generic shape without a challenge for a non-administrator while
     * SSO-only is on; the authorizeLoginUsing closure in AuthServiceProvider
     * additionally re-checks the policy behind the middleware as a
     * bypass guard (covered by the admin variant above + code review).
     */
    #[Test]
    public function sso_only_hides_challenge_from_non_administrators(): void
    {
        \App\Facades\Settings::set('useSsoOnly', true);

        $response = $this->postJson('/webauthn/login/options', ['email' => $this->user->email]);

        $response->assertStatus(401);
        $this->assertGuest();
    }
    /**
     * P1 regression (code review 261112): malformed base64url in the
     * binary members must be a clean 422 (validation rules) -- the vendor
     * deserializer throws bare RangeException for some of them, which
     * used to escape as a 500.
     */
    #[Test]
    public function malformed_base64url_members_are_validation_errors(): void
    {
        PasskeyAssertionFactory::convertedCredentialId($this->user);

        $challenge = $this->postJson('/webauthn/login/options', ['email' => $this->user->email])->json('challenge');

        $assertion = (PasskeyAssertionFactory::convertedCredential($this->user))->assertion($challenge);

        $payload = $assertion['credential'] + ['email' => $this->user->email];
        $payload['response']['clientDataJSON'] = '!!!not-base64url!!!';

        $this->postJson('/webauthn/login', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('response.clientDataJSON');

        $this->assertGuest();
    }

    /**
     * Register-side twin: malformed attestationObject is a 422, not 500.
     */
    #[Test]
    public function malformed_attestation_is_validation_error(): void
    {
        $this->actingAs($this->user, 'web-guard');

        $challenge = $this->json('POST', '/webauthn/register/options')->json('challenge');

        $attestation = PasskeyAttestationFactory::create($challenge);
        $attestation['response']['attestationObject'] = '###';

        $this->json('POST', '/webauthn/register', $attestation + ['name' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('response.attestationObject');
    }
}
