<?php

namespace Tests\Feature\Http\Auth;

use App\Extensions\WebauthnCredentialBroker;
use App\Http\Controllers\Auth\WebAuthnRecoveryController;
use App\Http\Requests\WebauthnRecoveryRequest;
use App\Models\User;
use App\Providers\AuthServiceProvider;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;
use Tests\Support\PasskeyAssertionFactory;

/**
 * WebAuthnRecoveryControllerTest test class
 */
#[CoversClass(WebAuthnRecoveryController::class)]
#[CoversClass(WebauthnCredentialBroker::class)]
#[CoversClass(WebauthnRecoveryRequest::class)]
#[CoversClass(AuthServiceProvider::class)]
class WebAuthnRecoveryControllerTest extends FeatureTestCase
{
    /**
     * @var \App\Models\User
     */
    protected $user;

    protected $now;

    const STORED_TOKEN_VALUE = '$2y$10$P6q8rl8te5QaO1EdpyJcNO0s9VFlVgf62KaItQhrPTskxfyu97mlW';

    const ACTUAL_TOKEN_VALUE = '9e583e3fb6c32034164ac62415c9657dcbd1fb861b434340b08a94c2075cac66';

    const CREDENTIAL_ID = '-VOLFKPY-_FuMI_sJ7gMllK76L3VoRUINj6lL_Z3qDg';

    protected function setUp() : void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        Date::setTestNow($this->now = Date::create(2022, 11, 16, 9, 4));

        DB::table(config('auth.passwords.webauthn.table'))->insert([
            'email'      => $this->user->email,
            'token'      => self::STORED_TOKEN_VALUE,
            'created_at' => $this->now->toDateTimeString(),
        ]);
    }

    #[Test]
    public function test_recover_fails_if_no_recovery_is_set()
    {
        DB::table(config('auth.passwords.webauthn.table'))->delete();

        $this->json('POST', '/webauthn/recover', [
            'token'    => self::ACTUAL_TOKEN_VALUE,
            'email'    => $this->user->email,
            'password' => UserFactory::USER_PASSWORD,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    #[Test]
    public function test_recover_with_wrong_token_returns_validation_error()
    {
        $response = $this->json('POST', '/webauthn/recover', [
            'token'    => 'wrong_token',
            'email'    => $this->user->email,
            'password' => UserFactory::USER_PASSWORD,
        ])
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors('email')
            ->assertJsonValidationErrors('token');
    }

    #[Test]
    public function test_recover_with_expired_token_returns_validation_error()
    {
        Date::setTestNow($now = Date::create(2020, 01, 01, 16, 30));

        DB::table(config('auth.passwords.webauthn.table'))->delete();
        DB::table(config('auth.passwords.webauthn.table'))->insert([
            'token'      => self::STORED_TOKEN_VALUE,
            'email'      => $this->user->email,
            'created_at' => $now->clone()->subHour()->subSecond()->toDateTimeString(),
        ]);

        $this->json('POST', '/webauthn/recover', [
            'token'    => self::ACTUAL_TOKEN_VALUE,
            'email'    => $this->user->email,
            'password' => UserFactory::USER_PASSWORD,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    #[Test]
    public function test_recover_with_invalid_password_returns_authentication_error()
    {
        $this->json('POST', '/webauthn/recover', [
            'token'    => self::ACTUAL_TOKEN_VALUE,
            'email'    => $this->user->email,
            'password' => 'bad_password',
        ])
            ->assertStatus(401);
    }

    #[Test]
    public function test_recover_returns_validation_error_when_no_user_exists()
    {
        $this->json('POST', '/webauthn/recover', [
            'token'    => self::ACTUAL_TOKEN_VALUE,
            'email'    => 'no@user.com',
            'password' => UserFactory::USER_PASSWORD,
        ])
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors('password')
            ->assertJsonMissingValidationErrors('token')
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function test_recover_returns_success()
    {
        $response = $this->json('POST', '/webauthn/recover', [
            'token'    => self::ACTUAL_TOKEN_VALUE,
            'email'    => $this->user->email,
            'password' => UserFactory::USER_PASSWORD,
        ])
            ->assertStatus(200);

        $this->assertDatabaseMissing(config('auth.passwords.webauthn.table'), [
            'token' => self::STORED_TOKEN_VALUE,
        ]);
    }

    #[Test]
    public function test_recover_resets_useWebauthnOnly_user_preference()
    {
        $this->user['preferences->useWebauthnOnly'] = true;
        $this->user->save();

        $response = $this->json('POST', '/webauthn/recover', [
            'token'    => self::ACTUAL_TOKEN_VALUE,
            'email'    => $this->user->email,
            'password' => UserFactory::USER_PASSWORD,
        ])
            ->assertStatus(200);

        $this->user->refresh();

        $this->assertFalse($this->user->preferences['useWebauthnOnly']);
    }
    #[Test]
    public function test_revoke_all_credentials_clear_registered_credentials()
    {
        PasskeyAssertionFactory::convertedCredentialId($this->user);

        $response = $this->json('POST', '/webauthn/recover', [
            'token'     => self::ACTUAL_TOKEN_VALUE,
            'email'     => $this->user->email,
            'password'  => UserFactory::USER_PASSWORD,
            'revokeAll' => true,
        ])
            ->assertStatus(200);

        $this->assertDatabaseMissing('passkeys', [
            'user_id' => $this->user->id,
        ]);
    }
}
