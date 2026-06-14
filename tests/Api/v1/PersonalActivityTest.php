<?php

namespace Tests\Api\v1;

use App\Enums\PersonalAction;
use App\Models\PersonalActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PersonalActivityTest test class
 */
#[CoversClass(\App\Api\v1\Controllers\PersonalActivityController::class)]
class PersonalActivityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_authenticated_user_can_list_their_activity()
    {
        $user = User::factory()->create();
        PersonalActivityLog::factory()->forUser($user)->count(3)->create();
        // Another user's logs must not leak
        PersonalActivityLog::factory()->create();

        $response = $this->actingAs($user, 'api-guard')->getJson('/api/v1/user/activity');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function test_authenticated_user_can_clear_their_activity()
    {
        $user = User::factory()->create();
        PersonalActivityLog::factory()->forUser($user)->count(2)->create();

        $response = $this->actingAs($user, 'api-guard')->deleteJson('/api/v1/user/activity');

        $response->assertStatus(204);
        $this->assertSame(0, PersonalActivityLog::where('user_id', $user->id)->count());
    }

    #[Test]
    public function test_clearing_activity_does_not_touch_other_users()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        PersonalActivityLog::factory()->forUser($user)->create();
        PersonalActivityLog::factory()->forUser($other)->create();

        $this->actingAs($user, 'api-guard')->deleteJson('/api/v1/user/activity');

        $this->assertSame(0, PersonalActivityLog::where('user_id', $user->id)->count());
        $this->assertSame(1, PersonalActivityLog::where('user_id', $other->id)->count());
    }

    #[Test]
    public function test_unauthenticated_request_returns_401()
    {
        $this->getJson('/api/v1/user/activity')->assertUnauthorized();
        $this->deleteJson('/api/v1/user/activity')->assertUnauthorized();
    }
}
