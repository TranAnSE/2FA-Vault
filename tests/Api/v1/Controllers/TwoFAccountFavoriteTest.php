<?php

namespace Tests\Api\v1\Controllers;

use App\Models\SharedAccount;
use App\Models\Team;
use App\Models\TwoFAccount;
use App\Models\User;
use App\Services\TeamService;
use App\Services\TwoFAccountService;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Tests\FeatureTestCase;
use TwoFAccounts;

class TwoFAccountFavoriteTest extends FeatureTestCase
{
    private User $owner;

    private User $other;

    protected function setUp() : void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['password' => Hash::make('password')]);
        $this->other = User::factory()->create(['password' => Hash::make('password')]);
        Passport::actingAs($this->owner, ['legacy_full_access'], 'api-guard');
    }

    /**
     * @covers \App\Api\v1\Controllers\TwoFAccountController::favorite
     */
    public function test_favorite_persists_and_serializes_is_favorite() : void
    {
        $account = TwoFAccount::factory()->for($this->owner)->create();

        $response = $this->json('PATCH', '/api/v1/twofaccounts/' . $account->id . '/favorite', ['is_favorite' => true]);

        $response->assertOk()->assertJsonFragment(['is_favorite' => true]);
        $this->assertDatabaseHas('twofaccount_user_favorites', ['user_id' => $this->owner->id, 'twofaccount_id' => $account->id]);

        // is_favorite is visible on a later read and index
        $this->json('GET', '/api/v1/twofaccounts/' . $account->id)->assertJsonFragment(['is_favorite' => true]);
        $this->json('GET', '/api/v1/twofaccounts')->assertJsonFragment(['is_favorite' => true]);

        $this->json('PATCH', '/api/v1/twofaccounts/' . $account->id . '/favorite', ['is_favorite' => false])->assertOk();
        $this->assertDatabaseMissing('twofaccount_user_favorites', ['user_id' => $this->owner->id, 'twofaccount_id' => $account->id]);
        $this->json('GET', '/api/v1/twofaccounts/' . $account->id)->assertJsonFragment(['is_favorite' => false]);
    }

    /**
     * Invisible and absent accounts are indistinguishable (no 403/404 oracle).
     *
     * @covers \App\Api\v1\Controllers\TwoFAccountController::favorite
     */
    public function test_favorite_masks_invisible_accounts_as_404() : void
    {
        $foreignAccount = TwoFAccount::factory()->for($this->other)->create();

        $this->json('PATCH', '/api/v1/twofaccounts/' . $foreignAccount->id . '/favorite', ['is_favorite' => true])
            ->assertNotFound();

        $this->json('PATCH', '/api/v1/twofaccounts/999999/favorite', ['is_favorite' => true])
            ->assertNotFound();
    }

    /**
     * A shared-with-me account is favorite-able by the member.
     *
     * @covers \App\Api\v1\Controllers\TwoFAccountController::favorite
     */
    public function test_favorite_allows_shared_with_me_member() : void
    {
        $account = TwoFAccount::factory()->for($this->other)->create();
        $team    = Team::factory()->for($this->other, 'owner')->create();
        $team->users()->attach([$this->other->id, $this->owner->id]);
        SharedAccount::create([
            'team_id'        => $team->id,
            'twofaccount_id' => $account->id,
            'shared_by'      => $this->other->id,
            'member_id'      => $this->owner->id,
            'access_level'   => 'read',
            'wrapped_key'    => 'wrapped',
        ]);

        $this->json('PATCH', '/api/v1/twofaccounts/' . $account->id . '/favorite', ['is_favorite' => true])
            ->assertOk()
            ->assertJsonFragment(['is_favorite' => true]);
    }

    /**
     * The -4 early-return branch eager-loads favorites so is_favorite is non-null.
     *
     * @covers \App\Api\v1\Controllers\TwoFAccountController::index
     */
    public function test_shared_with_me_index_serializes_is_favorite() : void
    {
        $account = TwoFAccount::factory()->for($this->other)->create();
        $team    = Team::factory()->for($this->other, 'owner')->create();
        $team->users()->attach([$this->other->id, $this->owner->id]);
        SharedAccount::create([
            'team_id'        => $team->id,
            'twofaccount_id' => $account->id,
            'shared_by'      => $this->other->id,
            'member_id'      => $this->owner->id,
            'access_level'   => 'read',
            'wrapped_key'    => 'wrapped',
        ]);

        TwoFAccounts::toggleFavorite($account->id, true, $this->owner);

        $response = $this->json('GET', '/api/v1/twofaccounts?group_id=-4');
        $response->assertOk();
        $this->assertNotNull($response->json('0.is_favorite'), 'is_favorite must be non-null on the -4 view');
        $this->assertTrue($response->json('0.is_favorite'));
    }

    /**
     * Validation rejects a missing or non-boolean is_favorite.
     *
     * @covers \App\Api\v1\Requests\TwoFAccountFavoriteRequest
     */
    public function test_favorite_validates_payload() : void
    {
        $account = TwoFAccount::factory()->for($this->owner)->create();

        $this->json('PATCH', '/api/v1/twofaccounts/' . $account->id . '/favorite', [])
            ->assertStatus(422);

        $this->json('PATCH', '/api/v1/twofaccounts/' . $account->id . '/favorite', ['is_favorite' => 'maybe'])
            ->assertStatus(422);
    }

    /**
     * Revoking a team membership drops the member's favorites on the team's accounts.
     *
     * @covers \App\Services\TeamService::removeMember
     */
    public function test_member_removal_cleans_up_favorites() : void
    {
        $team = Team::factory()->for($this->other, 'owner')->create();
        $team->users()->attach([$this->other->id, $this->owner->id]);
        $account = TwoFAccount::factory()->for($this->other)->create();
        SharedAccount::create([
            'team_id'        => $team->id,
            'twofaccount_id' => $account->id,
            'shared_by'      => $this->other->id,
            'member_id'      => $this->owner->id,
            'access_level'   => 'read',
            'wrapped_key'    => 'wrapped',
        ]);
        TwoFAccounts::toggleFavorite($account->id, true, $this->owner);

        app(TeamService::class)->removeMember($team, $this->other, $this->owner->id);

        $this->assertDatabaseMissing('twofaccount_user_favorites', ['user_id' => $this->owner->id, 'twofaccount_id' => $account->id]);
    }

    /**
     * Deleting a team drops every member's favorites on the team's accounts.
     *
     * @covers \App\Services\TeamService::deleteTeam
     */
    public function test_team_delete_cleans_up_favorites() : void
    {
        $team = Team::factory()->for($this->other, 'owner')->create();
        $team->users()->attach([$this->other->id, $this->owner->id]);
        $account = TwoFAccount::factory()->for($this->other)->create();
        SharedAccount::create([
            'team_id'        => $team->id,
            'twofaccount_id' => $account->id,
            'shared_by'      => $this->other->id,
            'member_id'      => $this->owner->id,
            'access_level'   => 'read',
            'wrapped_key'    => 'wrapped',
        ]);
        TwoFAccounts::toggleFavorite($account->id, true, $this->owner);

        app(TeamService::class)->deleteTeam($team, $this->other);

        $this->assertDatabaseMissing('twofaccount_user_favorites', ['user_id' => $this->owner->id, 'twofaccount_id' => $account->id]);
    }

    /**
     * Transferring ownership drops all favorites on the account.
     *
     * @covers \App\Services\TwoFAccountService::transferOwnership
     */
    public function test_ownership_transfer_cleans_up_favorites() : void
    {
        $account = TwoFAccount::factory()->for($this->owner)->create();
        TwoFAccounts::toggleFavorite($account->id, true, $this->owner);

        app(TwoFAccountService::class)->transferOwnership($account, $this->other);

        $this->assertDatabaseMissing('twofaccount_user_favorites', ['user_id' => $this->owner->id, 'twofaccount_id' => $account->id]);
    }

    /**
     * The enableFavorites preference is validated as boolean and lockable.
     *
     * @covers \App\Api\v1\Requests\SettingUpdateRequest
     */
    public function test_enable_favorites_preference_update() : void
    {
        $this->json('PUT', '/api/v1/user/preferences/enableFavorites', ['value' => false])
            ->assertSuccessful();

        $this->json('PUT', '/api/v1/user/preferences/enableFavorites', ['value' => 'not-a-bool'])
            ->assertStatus(422);
    }

    /**
     * The features endpoint reports the favorites feature.
     *
     * @covers \App\Api\v1\Controllers\FeatureFlagController
     */
    public function test_features_endpoint_reports_favorites() : void
    {
        $this->json('GET', '/api/v1/features')
            ->assertOk()
            ->assertJsonFragment(['name' => 'favorites', 'state' => 'enabled']);
    }

    /**
     * Deleting an account removes its favorites rows (FK cascade).
     *
     * @covers \App\Api\v1\Controllers\TwoFAccountController::destroy
     */
    public function test_account_destroy_cascades_favorites() : void
    {
        $account = TwoFAccount::factory()->for($this->owner)->create();
        TwoFAccounts::toggleFavorite($account->id, true, $this->owner);

        $this->json('DELETE', '/api/v1/twofaccounts/' . $account->id)->assertStatus(204);

        $this->assertDatabaseMissing('twofaccount_user_favorites', ['twofaccount_id' => $account->id]);
    }
}
