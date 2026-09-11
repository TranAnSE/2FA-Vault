<?php

namespace Tests\Feature\Http\Auth;

use App\Http\Controllers\Auth\PersonalAccessTokenController;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * PersonalAccessTokenExpirationTest (Phase 07 / upstream #536): the PAT list
 * response must expose each token's expires_at so the OAuth settings view can
 * render expiration info. Passport 13 serializes Token models without hidden
 * attributes, so the date columns ride along for free — this test pins that
 * contract.
 */
#[CoversClass(PersonalAccessTokenController::class)]
class PersonalAccessTokenExpirationTest extends FeatureTestCase
{
    #[Test]
    public function test_pat_list_includes_expires_at_and_created_at()
    {
        Artisan::call('passport:keys', ['--no-interaction' => 1]);
        app(\Laravel\Passport\ClientRepository::class)
            ->createPersonalAccessGrantClient(config('app.name'), 'users');

        $user = User::factory()->create();

        $this->actingAs($user, 'web-guard')
            ->json('POST', '/oauth/personal-access-tokens', [
                'name'   => 'extension',
                'scopes' => ['read'],
            ])
            ->assertStatus(200);

        $response = $this->actingAs($user, 'web-guard')
            ->json('GET', '/oauth/personal-access-tokens')
            ->assertStatus(200)
            ->assertJsonCount(1);

        $token = $response->json('0');

        $this->assertArrayHasKey('expires_at', $token, 'PAT list response must include expires_at');
        $this->assertArrayHasKey('created_at', $token, 'PAT list response must include created_at');
        $this->assertNotEmpty($token['expires_at']);

        // Fork PATs expire after 90 days (AppServiceProvider::personalAccessTokensExpireIn)
        $expiresAt = \Carbon\Carbon::parse($token['expires_at']);
        $this->assertEqualsWithDelta(90, now()->diffInDays($expiresAt), 1);
    }
}
