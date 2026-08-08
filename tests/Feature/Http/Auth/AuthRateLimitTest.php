<?php

namespace Tests\Feature\Http\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * AuthRateLimitTest test class
 *
 * Tests that registration and password reset routes are rate limited.
 */
class AuthRateLimitTest extends FeatureTestCase
{
    #[Test]
    public function test_registration_rate_limited_after_5_attempts() : void
    {
        // Ensure no users exist so registration is open
        DB::table('users')->delete();

        for ($i = 0; $i < 5; $i++) {
            $this->json('POST', '/user', [
                'name'                  => 'user' . $i,
                'email'                 => 'user' . $i . '@example.com',
                'password'              => 'password123',
                'password_confirmation' => 'password123',
            ]);
        }

        // 6th request should be rate limited
        $response = $this->json('POST', '/user', [
            'name'                  => 'user_limited',
            'email'                 => 'user_limited@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(429);
    }

    #[Test]
    public function test_password_reset_rate_limited_after_3_attempts() : void
    {
        $user  = User::factory()->create();
        $token = Password::broker()->createToken($user);

        for ($i = 0; $i < 3; $i++) {
            $this->json('POST', '/user/password/reset', [
                'email'                 => $user->email,
                'password'              => 'newpassword' . $i,
                'password_confirmation' => 'newpassword' . $i,
                'token'                 => $token,
            ]);
        }

        // 4th request should be rate limited
        $response = $this->json('POST', '/user/password/reset', [
            'email'                 => $user->email,
            'password'              => 'anotherpassword',
            'password_confirmation' => 'anotherpassword',
            'token'                 => $token,
        ]);

        $response->assertStatus(429);
    }

    #[Test]
    public function test_forgot_password_rate_limited_after_3_attempts() : void
    {
        Notification::fake();

        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->json('POST', '/user/password/lost', [
                'email' => $user->email,
            ]);
        }

        // 4th request should be rate limited
        $response = $this->json('POST', '/user/password/lost', [
            'email' => $user->email,
        ]);

        $response->assertStatus(429);
    }

    #[Test]
    public function test_rate_limit_returns_429_with_retry_after_header() : void
    {
        DB::table('users')->delete();

        for ($i = 0; $i < 5; $i++) {
            $this->json('POST', '/user', [
                'name'                  => 'ratelimit' . $i,
                'email'                 => 'ratelimit' . $i . '@example.com',
                'password'              => 'password123',
                'password_confirmation' => 'password123',
            ]);
        }

        $response = $this->json('POST', '/user', [
            'name'                  => 'ratelimit_exceeded',
            'email'                 => 'ratelimit_exceeded@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
    }

    #[Test]
    public function test_webauthn_device_lost_rate_limited_after_3_attempts() : void
    {
        // The device-lost recovery-email endpoint is a peer of password-lost
        // (both send outbound email from an unauthenticated caller) and carries
        // a per-IP route throttle of 3/60s. This test exercises the route
        // layer; the WebAuthn credential broker's own per-email throttle lives
        // in WebAuthnDeviceLostControllerTest. The route throttle fires before
        // the controller, so the broker's 422 responses still consume the
        // route throttle budget.
        Notification::fake();

        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->json('POST', '/webauthn/lost', [
                'email' => $user->email,
            ]);
        }

        // 4th request from the same IP should hit the route throttle (429).
        $response = $this->json('POST', '/webauthn/lost', [
            'email' => $user->email,
        ]);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
    }

    #[Test]
    public function test_webauthn_login_options_rate_limited_after_10_attempts() : void
    {
        // The WebAuthn assertion-options endpoint generates a challenge for an
        // unauthenticated caller and carries a per-IP route throttle of 10/min
        // (matching the login routes). After 10 requests the 11th is rejected
        // at the middleware layer before reaching the controller.
        $user = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->json('POST', '/webauthn/login/options', [
                'email' => $user->email,
            ]);
        }

        // 11th request from the same IP should be rate limited.
        $response = $this->json('POST', '/webauthn/login/options', [
            'email' => $user->email,
        ]);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
    }
}
