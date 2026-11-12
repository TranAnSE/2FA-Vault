<?php

namespace Tests\Feature\Http\Auth;

use App\Facades\Settings;
use App\Http\Controllers\Auth\WebAuthnLoginController;
use App\Http\Middleware\RejectIfSsoOnlyAndNotForAdmin;
use App\Listeners\Authentication\FailedLoginListener;
use App\Listeners\Authentication\LoginListener;
use App\Models\User;
use App\Notifications\SignedInWithNewDeviceNotification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;
use Tests\Support\PasskeyAssertionFactory;
use Tests\Support\PasskeyCredentialHandle;

/**
 * WebAuthn (passkey) login contract tests (plan 261009-passkeys-migration
 * phase 3): ceremonies run for real through the laravel/passkeys actions --
 * signature, origin, rpId, challenge and counter are verified by
 * webauthn-lib, with synthetic ES256 credentials from
 * Tests\Support\PasskeyAssertionFactory.
 */
#[CoversClass(WebAuthnLoginController::class)]
#[CoversClass(User::class)]
#[CoversClass(LoginListener::class)]
#[CoversClass(FailedLoginListener::class)]
#[CoversMethod(RejectIfSsoOnlyAndNotForAdmin::class, 'handle')]
class WebAuthnLoginControllerTest extends FeatureTestCase
{
    protected $user;

    private const GUARD = 'web-guard';

    private const AUTH_METHOD = 'webauthn';

    const EMAIL = 'john.doe@example.com';

    protected function setUp() : void
    {
        parent::setUp();

        DB::table('users')->delete();

        $this->user = User::factory()->create(['email' => self::EMAIL]);
    }

    /**
     * Seed a converted credential and run a full ceremony through the
     * endpoints: returns the login response.
     */
    protected function loginThroughRealCeremony(
        ?PasskeyCredentialHandle $handle = null,
        ?User $asUser = null,
        array $headers = []
    ) : \Illuminate\Testing\TestResponse {
        $handle ??= PasskeyAssertionFactory::convertedCredential($this->user);

        $asUser ??= $this->user;

        $challenge = $this->postJson('/webauthn/login/options', ['email' => $asUser->email])->json('challenge');

        $assertion = $handle->assertion($challenge);

        return $this->postJson(
            '/webauthn/login',
            $assertion['credential'] + ['email' => $asUser->email],
            $headers
        );
    }

    #[Test]
    public function test_webauthn_login_returns_success()
    {
        $this->loginThroughRealCeremony()
            ->assertOk()
            ->assertJsonFragment([
                'message'  => 'authenticated',
                'id'       => $this->user->id,
                'name'     => $this->user->name,
                'email'    => $this->user->email,
                'is_admin' => false,
            ])
            ->assertJsonStructure([
                'message',
                'id',
                'name',
                'email',
                'oauth_provider',
                'authenticated_by_proxy',
                'preferences',
                'is_admin',
                'encryption_version',
                'vault_locked',
                'last_backup_at',
            ]);
    }

    #[Test]
    public function test_webauthn_login_of_admin_returns_success_even_with_sso_only_enabled()
    {
        Settings::set('useSsoOnly', true);

        $this->user->promoteToAdministrator(true);
        $this->user->save();

        $this->loginThroughRealCeremony()
            ->assertOk()
            ->assertJsonFragment([
                'message'  => 'authenticated',
                'id'       => $this->user->id,
                'is_admin' => true,
            ]);
    }

    #[Test]
    public function test_webauthn_login_sends_new_device_notification_to_existing_user()
    {
        Notification::fake();

        $this->user['preferences->notifyOnNewAuthDevice'] = 1;
        $this->user->save();

        $handle = PasskeyAssertionFactory::convertedCredential($this->user);

        $this->loginThroughRealCeremony($handle)->assertOk();

        // A10: logout is POST-only now (the GET route answers 410).
        $this->actingAs($this->user, self::GUARD)
            ->json('POST', '/user/logout');

        $this->travel(1)->minute();

        $this->loginThroughRealCeremony($handle, headers: ['HTTP_USER_AGENT' => 'another_useragent_to_be_identified_as_new_device'])
            ->assertOk();

        Notification::assertSentTo($this->user, SignedInWithNewDeviceNotification::class);
    }

    #[Test]
    public function test_webauthn_login_does_not_send_new_device_notification_to_new_user()
    {
        Notification::fake();

        $this->user['preferences->notifyOnNewAuthDevice'] = 1;
        $this->user->save();

        $this->loginThroughRealCeremony()->assertOk();

        Notification::assertNothingSentTo($this->user);
    }

    #[Test]
    public function test_webauthn_login_does_not_send_new_device_notification_if_user_disabled_it()
    {
        Notification::fake();

        $this->user['preferences->notifyOnNewAuthDevice'] = 0;
        $this->user->save();

        $this->loginThroughRealCeremony()->assertOk();

        Notification::assertNothingSentTo($this->user);
    }

    #[Test]
    public function test_webauthn_admin_login_returns_admin_role()
    {
        DB::table('users')->delete();
        $this->user = User::factory()->administrator()->create(['email' => self::EMAIL]);

        $this->loginThroughRealCeremony()
            ->assertOk()
            ->assertJsonFragment([
                'is_admin' => true,
            ]);
    }

    #[Test]
    public function test_webauthn_login_merge_handle_if_missing()
    {
        // The synthetic assertion sends no userHandle; login must still
        // succeed on credential-id ownership alone.
        $this->loginThroughRealCeremony()
            ->assertOk()
            ->assertJsonFragment([
                'message' => 'authenticated',
                'name'    => $this->user->name,
            ]);
    }

    #[Test]
    public function test_webauthn_relogin_after_authentication_is_allowed()
    {
        $handle = PasskeyAssertionFactory::convertedCredential($reloginUser = $this->user);

        $this->loginThroughRealCeremony($handle)->assertOk();

        // A fresh ceremony (new challenge) may log the already-authenticated
        // device back in; the REPLAYED one-shot session options are gone,
        // which the M2 test covers.
        $this->loginThroughRealCeremony($handle)->assertOk();
    }

    #[Test]
    public function test_webauthn_login_with_missing_data_returns_validation_error()
    {
        $data = [
            'id'       => '',
            'rawId'    => '',
            'type'     => '',
            'response' => [
                'authenticatorData' => '',
                'clientDataJSON'    => '',
                'signature'         => '',
                'userHandle'        => null,
            ],
        ];

        $this->json('POST', '/webauthn/login', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'id',
                'rawId',
                'type',
                'response.authenticatorData',
                'response.clientDataJSON',
                'response.signature',
            ]);
    }

    #[Test]
    public function test_webauthn_invalid_login_returns_unauthorized()
    {
        $handle = PasskeyAssertionFactory::convertedCredential($this->user);

        $challenge = $this->postJson('/webauthn/login/options', ['email' => $this->user->email])->json('challenge');

        $bad = $handle->wrongKeyAssertion($challenge);

        $this->postJson('/webauthn/login', $bad['credential'] + ['email' => $this->user->email])
            ->assertUnauthorized();
    }

    #[Test]
    public function test_too_many_invalid_login_attempts_returns_too_many_request_error()
    {
        $throttle = 8;
        Config::set('auth.throttle.login', $throttle);

        $handle = PasskeyAssertionFactory::convertedCredential($this->user);

        $challenge = $this->postJson('/webauthn/login/options', ['email' => $this->user->email])->json('challenge');

        $bad = $handle->wrongKeyAssertion($challenge);

        for ($i = 0; $i < $throttle; $i++) {
            $this->postJson('/webauthn/login', $bad['credential'] + ['email' => $this->user->email])
                ->assertUnauthorized();
        }

        $this->postJson('/webauthn/login', $bad['credential'] + ['email' => $this->user->email])
            ->assertStatus(429);
    }

    #[Test]
    public function test_get_options_returns_success()
    {
        $credentialId = PasskeyAssertionFactory::convertedCredentialId($this->user);

        $this->json('POST', '/webauthn/login/options', [
            'email' => $this->user->email,
        ])
            ->assertOk()
            ->assertJsonStructure([
                'challenge',
                'timeout',
            ])
            ->assertJsonFragment([
                'allowCredentials' => [[
                    'id'   => $credentialId,
                    'type' => 'public-key',
                ]],
            ]);
    }

    #[Test]
    public function test_get_options_with_capitalized_email_returns_success()
    {
        $this->json('POST', '/webauthn/login/options', [
            'email' => strtoupper($this->user->email),
        ])
            ->assertOk();
    }

    #[Test]
    public function test_get_options_with_missing_email_returns_validation_errors()
    {
        $this->json('POST', '/webauthn/login/options', [
            'email' => null,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'email',
            ]);
    }

    #[Test]
    public function test_get_options_with_invalid_email_returns_validation_errors()
    {
        $this->json('POST', '/webauthn/login/options', [
            'email' => 'invalid',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'email',
            ]);
    }

    #[Test]
    public function test_get_options_with_unknown_email_returns_200_shaped_generic_error()
    {
        // A8: a 422-vs-200 difference was a registered-email enumeration
        // oracle; unknown emails now get a 200-shaped generic error.
        $this->json('POST', '/webauthn/login/options', [
            'email' => 'john@example.com',
        ])
            ->assertStatus(200)
            ->assertJsonMissingValidationErrors();
    }

    /**
     * D7 (plan 261009): the former config('webauthn.user_verification')
     * switch was dead and is intentionally not reproduced -- options ALWAYS
     * request user verification.
     */
    #[Test]
    public function test_get_options_always_emit_required_user_verification()
    {
        Config::set('webauthn.user_verification', 'discouraged');

        PasskeyAssertionFactory::convertedCredentialId($this->user);

        $this->json('POST', '/webauthn/login/options', [
            'email' => $this->user->email,
        ])
            ->assertOk()
            ->assertJsonFragment([
                'userVerification' => 'required',
            ]);
    }

    #[Test]
    public function test_successful_webauthn_login_is_logged()
    {
        $this->loginThroughRealCeremony()
            ->assertOk();

        $this->assertDatabaseHas('auth_logs', [
            'authenticatable_id' => $this->user->id,
            'login_successful'   => true,
            'guard'              => self::GUARD,
            'login_method'       => self::AUTH_METHOD,
            'logout_at'          => null,
        ]);
    }

    #[Test]
    public function test_failed_webauthn_login_is_not_logged()
    {
        $handle = PasskeyAssertionFactory::convertedCredential($this->user);

        $challenge = $this->postJson('/webauthn/login/options', ['email' => $this->user->email])->json('challenge');

        $bad = $handle->wrongKeyAssertion($challenge);

        $this->postJson('/webauthn/login', $bad['credential'] + ['email' => $this->user->email])
            ->assertUnauthorized();

        // A failed ceremony never reaches the guard, so nothing is logged.
        $this->assertDatabaseMissing('auth_logs', [
            'authenticatable_id' => $this->user->id,
            'guard'              => self::GUARD,
            'login_method'       => self::AUTH_METHOD,
        ]);
    }

    #[Test]
    public function test_legacy_login_is_rejected_when_webauthn_only_is_enable()
    {
        $this->user['preferences->useWebauthnOnly'] = true;
        $this->user->save();

        $this->json('POST', '/user/login', [
            'email'    => self::EMAIL,
            'password' => 'password',
        ])
            ->assertUnauthorized();
    }
}
