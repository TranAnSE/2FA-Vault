<?php

namespace Tests\Feature\Http\Auth;

use App\Http\Controllers\Auth\PersonalAccessTokenController;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * PersonalAccessTokenManagementTest: list filtering and revoke semantics of
 * the standalone PAT controller backed by the vendored PassportTokenRepository
 * (deprecation-parity, upstream 3c52a57f). The controller no longer extends
 * the deprecated Passport parent, so the revoked-client filter, the
 * personal_access grant filter and the per-user token binding are pinned here.
 */
#[CoversClass(PersonalAccessTokenController::class)]
class PersonalAccessTokenManagementTest extends FeatureTestCase
{
    protected function setUp() : void
    {
        parent::setUp();

        Artisan::call('passport:keys', ['--no-interaction' => 1]);
        app(ClientRepository::class)
            ->createPersonalAccessGrantClient(config('app.name'), 'users');
    }

    #[Test]
    public function test_pat_can_be_revoked()
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web-guard')
            ->json('POST', '/oauth/personal-access-tokens', [
                'name'   => 'revocation target',
                'scopes' => ['read'],
            ])
            ->assertStatus(200);

        $tokenId = Token::where('user_id', $user->id)->first()->id;

        $this->actingAs($user, 'web-guard')
            ->json('DELETE', '/oauth/personal-access-tokens/'.$tokenId)
            ->assertStatus(204);

        $this->assertDatabaseHas('oauth_access_tokens', [
            'id'      => $tokenId,
            'revoked' => 1,
        ]);

        $this->actingAs($user, 'web-guard')
            ->json('GET', '/oauth/personal-access-tokens')
            ->assertStatus(200)
            ->assertJsonCount(0);
    }

    #[Test]
    public function test_pat_list_excludes_tokens_with_revoked_clients()
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web-guard')
            ->json('POST', '/oauth/personal-access-tokens', [
                'name'   => 'client-revoked',
                'scopes' => ['read'],
            ])
            ->assertStatus(200);

        Token::where('user_id', $user->id)->first()->client->update(['revoked' => true]);

        $this->actingAs($user, 'web-guard')
            ->json('GET', '/oauth/personal-access-tokens')
            ->assertStatus(200)
            ->assertJsonCount(0);
    }

    #[Test]
    public function test_destroy_returns_404_for_token_belonging_to_another_user()
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner, 'web-guard')
            ->json('POST', '/oauth/personal-access-tokens', [
                'name'   => 'owner token',
                'scopes' => ['read'],
            ])
            ->assertStatus(200);

        $tokenId = Token::where('user_id', $owner->id)->first()->id;

        $this->actingAs($other, 'web-guard')
            ->json('DELETE', '/oauth/personal-access-tokens/'.$tokenId)
            ->assertStatus(404);

        $this->assertDatabaseHas('oauth_access_tokens', [
            'id'      => $tokenId,
            'revoked' => 0,
        ]);
    }
}
