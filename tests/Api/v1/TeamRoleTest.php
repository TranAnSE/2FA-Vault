<?php

namespace Tests\Api\v1;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamRole;
use App\Models\User;
use App\Support\TeamPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Team role CRUD endpoint tests (plan phase 7): auth matrix
 * (owner/admin/member/viewer × CRUD), validation rules, delete guards
 * (assigned members, pending invitations, system presets), and the
 * accept-after-delete fail-closed backstop.
 */
class TeamRoleTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    protected function setUp() : void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->team  = Team::factory()->create(['owner_id' => $this->owner->id]);
    }

    private function actingAsTeamUser(User $user) : void
    {
        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
    }

    private function memberWithRole(string $role) : User
    {
        $user = User::factory()->create();
        $this->team->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);

        return $user;
    }

    private function validPayload(array $overrides = []) : array
    {
        return array_merge([
            'slug'        => 'auditor',
            'name'        => 'Auditor',
            'permissions' => [TeamPermission::ACTIVITY_VIEW],
        ], $overrides);
    }

    // ---- Auth matrix ----

    public function test_any_member_can_list_roles() : void
    {
        foreach (['admin', 'member', 'viewer'] as $role) {
            $this->actingAsTeamUser($this->memberWithRole($role));

            $this->getJson('/api/v1/teams/' . $this->team->id . '/roles')
                ->assertStatus(200)
                ->assertJsonCount(4)
                ->assertJsonStructure([['id', 'slug', 'name', 'permissions', 'is_system', 'members_count']]);
        }
    }

    public function test_non_member_cannot_list_roles() : void
    {
        $this->actingAsTeamUser(User::factory()->create());

        $this->getJson('/api/v1/teams/' . $this->team->id . '/roles')
            ->assertStatus(403);
    }

    public function test_only_owner_can_create_update_or_delete_roles() : void
    {
        $admin = $this->memberWithRole('admin');
        $role  = TeamRole::create([
            'team_id'     => $this->team->id, 'slug' => 'dev', 'name' => 'Dev',
            'permissions' => [TeamPermission::TEAM_EDIT], 'is_system' => false,
        ]);

        foreach (['admin', 'member', 'viewer'] as $acting) {
            $user = $acting === 'admin' ? $admin : $this->memberWithRole($acting);
            $this->actingAsTeamUser($user);

            $this->postJson('/api/v1/teams/' . $this->team->id . '/roles', $this->validPayload())
                ->assertStatus(403);
            $this->putJson('/api/v1/teams/' . $this->team->id . '/roles/' . $role->id, $this->validPayload(['slug' => null, 'name' => 'X']))
                ->assertStatus(403);
            $this->deleteJson('/api/v1/teams/' . $this->team->id . '/roles/' . $role->id)
                ->assertStatus(403);
        }

        $this->actingAsTeamUser($this->owner);
        $this->postJson('/api/v1/teams/' . $this->team->id . '/roles', $this->validPayload())
            ->assertStatus(201);
    }

    // ---- Validation ----

    public function test_store_rejects_invalid_slug_format() : void
    {
        $this->actingAsTeamUser($this->owner);

        foreach (['A', 'UPPER', 'a', 'has space', 'x' . str_repeat('y', 35)] as $bad) {
            $this->postJson('/api/v1/teams/' . $this->team->id . '/roles', $this->validPayload(['slug' => $bad]))
                ->assertStatus(422);
        }
    }

    public function test_store_requires_a_slug_instead_of_erroring() : void
    {
        // A missing slug must 422, not fall through to TeamRole::create and 500.
        $this->actingAsTeamUser($this->owner);

        $payload = $this->validPayload();
        unset($payload['slug']);

        $this->postJson('/api/v1/teams/' . $this->team->id . '/roles', $payload)
            ->assertStatus(422);
    }

    public function test_store_rejects_reserved_slugs_and_duplicates() : void
    {
        $this->actingAsTeamUser($this->owner);

        foreach (['owner', 'admin', 'member', 'viewer'] as $reserved) {
            $this->postJson('/api/v1/teams/' . $this->team->id . '/roles', $this->validPayload(['slug' => $reserved]))
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/teams/' . $this->team->id . '/roles', $this->validPayload())
            ->assertStatus(201);
        $this->postJson('/api/v1/teams/' . $this->team->id . '/roles', $this->validPayload())
            ->assertStatus(422);
    }

    public function test_store_rejects_unknown_and_empty_permissions() : void
    {
        $this->actingAsTeamUser($this->owner);

        $this->postJson('/api/v1/teams/' . $this->team->id . '/roles', $this->validPayload([
            'permissions' => ['team.edit', 'galaxy.destroy'],
        ]))->assertStatus(422);

        $this->postJson('/api/v1/teams/' . $this->team->id . '/roles', $this->validPayload([
            'permissions' => [],
        ]))->assertStatus(422);
    }

    public function test_update_cannot_modify_system_roles_or_change_slug() : void
    {
        $this->actingAsTeamUser($this->owner);
        $systemRole = $this->team->roles()->where('slug', 'admin')->first();

        $this->putJson('/api/v1/teams/' . $this->team->id . '/roles/' . $systemRole->id, [
            'name'        => 'Hacked Admin',
            'permissions' => TeamPermission::all(),
        ])->assertStatus(422);

        $custom = TeamRole::create([
            'team_id'     => $this->team->id, 'slug' => 'dev', 'name' => 'Dev',
            'permissions' => [TeamPermission::TEAM_EDIT], 'is_system' => false,
        ]);

        $this->putJson('/api/v1/teams/' . $this->team->id . '/roles/' . $custom->id, [
            'slug'        => 'other',
            'name'        => 'Dev',
            'permissions' => [TeamPermission::TEAM_EDIT],
        ])->assertStatus(422);
    }

    // ---- Delete guards ----

    public function test_cannot_delete_a_role_still_assigned_to_members() : void
    {
        $this->actingAsTeamUser($this->owner);
        $member = $this->memberWithRole('dev');
        $role   = TeamRole::create([
            'team_id'     => $this->team->id, 'slug' => 'dev', 'name' => 'Dev',
            'permissions' => [TeamPermission::TEAM_EDIT], 'is_system' => false,
        ]);
        $this->team->users()->updateExistingPivot($member->id, ['role' => 'dev']);

        $this->deleteJson('/api/v1/teams/' . $this->team->id . '/roles/' . $role->id)
            ->assertStatus(422)
            ->assertJsonPath('members_count', 1);
    }

    public function test_cannot_delete_a_role_targeted_by_pending_invitations() : void
    {
        $this->actingAsTeamUser($this->owner);
        $role = TeamRole::create([
            'team_id'     => $this->team->id, 'slug' => 'dev', 'name' => 'Dev',
            'permissions' => [TeamPermission::TEAM_EDIT], 'is_system' => false,
        ]);
        TeamInvitation::create([
            'team_id' => $this->team->id, 'email' => 'x@example.com', 'role' => 'dev',
            'token'   => 'tok', 'status' => 'pending', 'expires_at' => now()->addDay(),
        ]);

        $this->deleteJson('/api/v1/teams/' . $this->team->id . '/roles/' . $role->id)
            ->assertStatus(422)
            ->assertJsonPath('invitations_count', 1);
    }

    public function test_cannot_delete_system_presets() : void
    {
        $this->actingAsTeamUser($this->owner);
        $systemRole = $this->team->roles()->where('slug', 'member')->first();

        $this->deleteJson('/api/v1/teams/' . $this->team->id . '/roles/' . $systemRole->id)
            ->assertStatus(422);
    }

    // ---- End-to-end: custom role power flows through the matrix ----

    public function test_custom_role_end_to_end_create_assign_enforced() : void
    {
        $this->actingAsTeamUser($this->owner);

        $created = $this->postJson('/api/v1/teams/' . $this->team->id . '/roles', [
            'slug'        => 'sharer',
            'name'        => 'Sharer',
            'permissions' => [TeamPermission::TEAM_EDIT],
        ])->assertStatus(201)->json();

        $member = $this->memberWithRole('member');

        // Assign via the member-role endpoint (owner, subset-exempt).
        $this->putJson('/api/v1/teams/' . $this->team->id . '/members/' . $member->id . '/role', [
            'role' => 'sharer',
        ])->assertStatus(200);

        // The permission is now enforced: the member can rename the team.
        $this->actingAsTeamUser($member);
        $this->putJson('/api/v1/teams/' . $this->team->id, ['name' => 'Renamed'])
            ->assertStatus(200);
        $this->assertSame('sharer', $this->team->getUserRole($member->id));
        $this->assertSame(['team.edit'], $created['permissions']);
    }

    public function test_accept_after_role_delete_fails_closed_without_500() : void
    {
        $this->actingAsTeamUser($this->owner);

        $this->postJson('/api/v1/teams/' . $this->team->id . '/roles', $this->validPayload())
            ->assertStatus(201);

        $invitee    = User::factory()->create(['email' => 'late-accept@example.com']);
        $invitation = TeamInvitation::create([
            'team_id' => $this->team->id, 'email' => $invitee->email, 'role' => 'auditor',
            'token'   => 'late-tok', 'status' => 'pending', 'expires_at' => now()->addDay(),
        ]);

        // The invitation was cancelled first (delete guard), but simulate the
        // racing window: force the role away under the invitation.
        $role = $this->team->roles()->where('slug', 'auditor')->first();
        $invitation->update(['status' => 'pending']);
        DB::table('team_roles')->where('id', $role->id)->delete();

        $this->actingAsTeamUser($invitee);
        $this->postJson('/api/v1/teams/invitations/late-tok/accept')
            ->assertStatus(400);

        // The user is NOT a member and has no permissions.
        $this->assertFalse($this->team->hasMember($invitee->id));
        $this->assertSame([], $this->team->permissionsFor($invitee)->toArray());
    }
}
