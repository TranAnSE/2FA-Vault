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
    public function test_registration_rate_limited_after_5_attempts(): void
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
    public function test_password_reset_rate_limited_after_3_attempts(): void
    {
        $user = User::factory()->create();
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
    public function test_forgot_password_rate_limited_after_3_attempts(): void
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
    public function test_rate_limit_returns_429_with_retry_after_header(): void
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
}
