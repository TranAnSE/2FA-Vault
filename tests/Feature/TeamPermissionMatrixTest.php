<?php

namespace Tests\Feature;

use App\Models\SharedAccount;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamRole;
use App\Models\TwoFAccount;
use App\Models\User;
use App\Services\TeamService;
use App\Support\TeamPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Permission-matrix tests (plan phase 6).
 *
 * The preset outcome grid doubles as the v1.3.x before/after regression
 * scenario: the presets in TeamPermission::systemPresets() were derived
 * from the pre-matrix CODE behavior, and this grid pins every
 * (role, ability) pair so preset mapping drift cannot slip through
 * silently (RT-8 behavior-compat gate).
 */
class TeamPermissionMatrixTest extends TestCase
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

    private function memberWithRole(User $user, string $role) : User
    {
        $this->team->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);

        return $user;
    }

    // ---- Seeding ----

    public function test_new_teams_get_the_four_system_presets() : void
    {
        $slugs = $this->team->roles()->pluck('slug')->all();
        sort($slugs);

        $this->assertSame(['admin', 'member', 'owner', 'viewer'], $slugs);
        $this->assertTrue($this->team->roles()->where('slug', 'admin')->first()->is_system);
    }

    public function test_seeding_is_idempotent() : void
    {
        TeamRole::seedSystemPresets($this->team);

        $this->assertSame(4, $this->team->roles()->count());
    }

    public function test_admin_preset_excludes_roles_assign() : void
    {
        $permissions = $this->team->roles()->where('slug', 'admin')->first()->permissions;

        $this->assertContains(TeamPermission::TEAM_EDIT, $permissions);
        $this->assertContains(TeamPermission::ACTIVITY_VIEW, $permissions);
        $this->assertContains(TeamPermission::ACCOUNTS_UNSHARE_OTHERS, $permissions);
        $this->assertNotContains(TeamPermission::ROLES_ASSIGN, $permissions);
    }

    public function test_member_and_viewer_presets_only_share() : void
    {
        foreach (['member', 'viewer'] as $slug) {
            $this->assertSame(
                [TeamPermission::ACCOUNTS_SHARE],
                $this->team->roles()->where('slug', $slug)->first()->permissions,
                "the {$slug} preset must reproduce the v1.3.x quirk exactly"
            );
        }
    }

    // ---- Behavior-compat outcome grid (before/after regression) ----

    public function test_preset_outcome_grid_matches_v13x_behavior() : void
    {
        $admin  = $this->memberWithRole(User::factory()->create(), 'admin');
        $member = $this->memberWithRole(User::factory()->create(), 'member');
        $viewer = $this->memberWithRole(User::factory()->create(), 'viewer');

        // [role => ability => expected] — v1.3.x effective behavior.
        $grid = [
            'owner' => ['view' => true, 'update' => true, 'delete' => true, 'invite' => true,
                'removeMember' => true, 'updateRole' => true, 'transferOwnership' => true,
                'viewActivity' => true, 'exportActivity' => true, 'unshareOthers' => true, 'manageRoles' => true],
            'admin' => ['view' => true, 'update' => true, 'delete' => false, 'invite' => true,
                'removeMember' => true, 'updateRole' => false, 'transferOwnership' => false,
                'viewActivity' => true, 'exportActivity' => false, 'unshareOthers' => true, 'manageRoles' => false],
            'member' => ['view' => true, 'update' => false, 'delete' => false, 'invite' => false,
                'removeMember'  => false, 'updateRole' => false, 'transferOwnership' => false,
                'viewActivity'  => false, 'exportActivity' => false, 'unshareOthers' => false, 'manageRoles' => false],
            'viewer' => ['view' => true, 'update' => false, 'delete' => false, 'invite' => false,
                'removeMember'  => false, 'updateRole' => false, 'transferOwnership' => false,
                'viewActivity'  => false, 'exportActivity' => false, 'unshareOthers' => false, 'manageRoles' => false],
        ];

        $usersByRole = ['owner' => $this->owner, 'admin' => $admin, 'member' => $member, 'viewer' => $viewer];

        foreach ($grid as $role => $expectations) {
            foreach ($expectations as $ability => $expected) {
                $this->assertSame(
                    $expected,
                    Gate::forUser($usersByRole[$role])->allows($ability, $this->team),
                    "{$role}.{$ability} must be " . ($expected ? 'allowed' : 'denied')
                );
            }
        }
    }

    public function test_non_member_gets_nothing() : void
    {
        $outsider = User::factory()->create();

        $this->assertFalse(Gate::forUser($outsider)->allows('view', $this->team));
        $this->assertFalse(Gate::forUser($outsider)->allows('update', $this->team));
        $this->assertSame([], $this->team->permissionsFor($outsider)->toArray());
    }

    // ---- RT-1: owner-slug escalation guard ----

    public function test_invite_with_owner_role_answers_422() : void
    {
        Passport::actingAs($this->owner, ['legacy_full_access'], 'api-guard');

        $this->postJson('/api/v1/teams/' . $this->team->id . '/invite', [
            'email' => 'newmember@example.com',
            'role'  => 'owner',
        ])->assertStatus(422);
    }

    public function test_invite_with_unknown_role_answers_422() : void
    {
        Passport::actingAs($this->owner, ['legacy_full_access'], 'api-guard');

        $this->postJson('/api/v1/teams/' . $this->team->id . '/invite', [
            'email' => 'newmember@example.com',
            'role'  => 'ghost',
        ])->assertStatus(422);
    }

    public function test_accept_revalidates_a_tampered_owner_role() : void
    {
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);

        $invitation = TeamInvitation::create([
            'team_id'    => $this->team->id,
            'email'      => $invitee->email,
            'role'       => 'member',
            'token'      => 'valid-token',
            'status'     => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        // Simulate a tampered/stale row carrying the reserved owner slug.
        $invitation->update(['role' => 'owner']);

        $this->expectException(\Exception::class);
        app(TeamService::class)->acceptInvitation($invitation->fresh(), $invitee);
    }

    public function test_accept_revalidates_a_deleted_role() : void
    {
        $invitee = User::factory()->create(['email' => 'invitee2@example.com']);

        // Custom role deleted after the invitation was sent.
        $invitation = TeamInvitation::create([
            'team_id'    => $this->team->id,
            'email'      => $invitee->email,
            'role'       => 'ghost-role',
            'token'      => 'token-2',
            'status'     => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        $this->expectException(\Exception::class);
        app(TeamService::class)->acceptInvitation($invitation->fresh(), $invitee);
    }

    public function test_poisoned_owner_pivot_confers_nothing() : void
    {
        // A non-owner member whose pivot role was manually set to 'owner'
        // must NOT pass the owner short-circuit (keyed on owner_id only).
        $poisoned = $this->memberWithRole(User::factory()->create(), 'admin');
        $this->team->users()->updateExistingPivot($poisoned->id, ['role' => 'owner']);

        $this->assertSame(
            TeamPermission::all(),
            $this->team->permissionsFor($poisoned)->toArray(),
            'the seeded owner preset defines the full catalog, but...'
        );
        $this->assertFalse(Gate::forUser($poisoned)->allows('delete', $this->team), '...owner-locked abilities stay owner_id-keyed');
        $this->assertFalse(Gate::forUser($poisoned)->allows('transferOwnership', $this->team));
        $this->assertFalse(Gate::forUser($poisoned)->allows('manageRoles', $this->team));
        $this->assertFalse(Gate::forUser($poisoned)->allows('exportActivity', $this->team));
    }

    // ---- RT-15: dangling slug fails closed ----

    public function test_dangling_role_slug_fails_closed_without_exception() : void
    {
        $ghosted = $this->memberWithRole(User::factory()->create(), 'vanished-role');

        $this->assertSame([], $this->team->permissionsFor($ghosted)->toArray());
        $this->assertFalse(Gate::forUser($ghosted)->allows('update', $this->team));
        $this->assertFalse(Gate::forUser($ghosted)->allows('invite', $this->team));
        // Membership itself still resolves.
        $this->assertTrue(Gate::forUser($ghosted)->allows('view', $this->team));
    }

    // ---- RT-15: subset-constrained assignment ----

    private function makeCustomRole(string $slug, array $permissions) : TeamRole
    {
        return TeamRole::create([
            'team_id'     => $this->team->id,
            'slug'        => $slug,
            'name'        => ucfirst($slug),
            'permissions' => $permissions,
            'is_system'   => false,
        ]);
    }

    public function test_deputy_cannot_assign_a_role_exceeding_their_own_permissions() : void
    {
        // Deputy: may assign roles, but holds nothing else.
        $deputy = $this->memberWithRole(User::factory()->create(), 'deputy');
        $this->makeCustomRole('deputy', [TeamPermission::ROLES_ASSIGN]);
        $member = $this->memberWithRole(User::factory()->create(), 'member');

        // Assigning the admin preset (a SUPERSET of the deputy's powers) must fail.
        $this->expectException(\Exception::class);
        app(TeamService::class)->updateMemberRole($this->team, $deputy, $member->id, 'admin');
    }

    public function test_deputy_can_assign_a_subset_role() : void
    {
        $deputy = $this->memberWithRole(User::factory()->create(), 'deputy');
        $this->makeCustomRole('deputy', [TeamPermission::ROLES_ASSIGN, TeamPermission::TEAM_EDIT]);
        $this->makeCustomRole('editor', [TeamPermission::TEAM_EDIT]);
        $member = $this->memberWithRole(User::factory()->create(), 'member');

        $result = app(TeamService::class)->updateMemberRole($this->team, $deputy, $member->id, 'editor');

        $this->assertTrue($result);
        $this->assertSame('editor', $this->team->getUserRole($member->id));
    }

    public function test_owner_still_passes_the_subset_gate_exempt() : void
    {
        $member = $this->memberWithRole(User::factory()->create(), 'member');

        $result = app(TeamService::class)->updateMemberRole($this->team, $this->owner, $member->id, 'admin');

        $this->assertTrue($result);
        $this->assertSame('admin', $this->team->getUserRole($member->id));
    }

    public function test_recruiter_cannot_invite_a_role_exceeding_their_own_permissions() : void
    {
        // The invite path carries the same subset constraint as role
        // assignment: a members.invite-only member must not mint, by
        // invitation, an admin membership (a strict superset of their powers).
        $recruiter = $this->memberWithRole(User::factory()->create(), 'recruiter');
        $this->makeCustomRole('recruiter', [TeamPermission::MEMBERS_INVITE]);

        $this->expectException(\Exception::class);
        app(TeamService::class)->inviteUser($this->team, $recruiter, 'newmember@example.com', 'admin');
    }

    public function test_recruiter_can_invite_a_subset_role() : void
    {
        $recruiter = $this->memberWithRole(User::factory()->create(), 'recruiter');
        $this->makeCustomRole('recruiter', [TeamPermission::MEMBERS_INVITE, TeamPermission::TEAM_EDIT]);
        $this->makeCustomRole('editor', [TeamPermission::TEAM_EDIT]);

        $invitation = app(TeamService::class)->inviteUser($this->team, $recruiter, 'newmember@example.com', 'editor');

        $this->assertSame('editor', $invitation->role);
    }

    public function test_sharing_requires_the_accounts_share_permission() : void
    {
        // accounts.share is a real gate, not a decorative matrix toggle: a
        // custom role without it cannot share, even its own account.
        $restricted = $this->memberWithRole(User::factory()->create(), 'restricted');
        $this->makeCustomRole('restricted', [TeamPermission::TEAM_EDIT]);
        $account = TwoFAccount::factory()->forUser($restricted)->create();

        Passport::actingAs($restricted, ['legacy_full_access'], 'api-guard');

        $this->postJson('/api/v1/teams/' . $this->team->id . '/share', [
            'twofaccount_id' => $account->id,
        ])->assertStatus(403);
        $this->assertDatabaseMissing('shared_accounts', ['team_id' => $this->team->id, 'twofaccount_id' => $account->id]);
    }

    public function test_member_preset_still_shares_own_account() : void
    {
        // v1.3.x behavior-compat: the member preset carries accounts.share.
        $member = $this->memberWithRole(User::factory()->create(), 'member');
        $account = TwoFAccount::factory()->forUser($member)->create();

        Passport::actingAs($member, ['legacy_full_access'], 'api-guard');

        $this->postJson('/api/v1/teams/' . $this->team->id . '/share', [
            'twofaccount_id' => $account->id,
        ])->assertStatus(201);
    }

    public function test_update_member_role_with_owner_slug_answers_422() : void
    {
        Passport::actingAs($this->owner, ['legacy_full_access'], 'api-guard');
        $member = $this->memberWithRole(User::factory()->create(), 'member');

        $this->putJson('/api/v1/teams/' . $this->team->id . '/members/' . $member->id . '/role', [
            'role' => 'owner',
        ])->assertStatus(422);
    }

    // ---- Owner-locked abilities can never be matrix-granted ----

    public function test_custom_roles_cannot_reach_owner_locked_abilities() : void
    {
        // Even a hand-crafted role with EVERY catalog permission stays
        // short of the owner-locked abilities.
        $supermember = $this->memberWithRole(User::factory()->create(), 'super');
        $this->makeCustomRole('super', TeamPermission::all());

        $this->assertTrue(Gate::forUser($supermember)->allows('update', $this->team));
        $this->assertFalse(Gate::forUser($supermember)->allows('delete', $this->team));
        $this->assertFalse(Gate::forUser($supermember)->allows('transferOwnership', $this->team));
        $this->assertFalse(Gate::forUser($supermember)->allows('manageRoles', $this->team));
        $this->assertFalse(Gate::forUser($supermember)->allows('exportActivity', $this->team));
    }

    // ---- Matrix grants flow through to real endpoints ----

    public function test_custom_team_edit_role_can_rename_the_team() : void
    {
        $editor = $this->memberWithRole(User::factory()->create(), 'editor');
        $this->makeCustomRole('editor', [TeamPermission::TEAM_EDIT]);

        Passport::actingAs($editor, ['legacy_full_access'], 'api-guard');

        $this->putJson('/api/v1/teams/' . $this->team->id, ['name' => 'Renamed'])
            ->assertStatus(200)
            ->assertJsonPath('name', 'Renamed');
    }

    public function test_viewer_can_still_unshare_their_own_share() : void
    {
        // v1.3.x quirk preserved: sharing + unsharing one's OWN share is a
        // universal member rule, not a permission.
        $viewer  = $this->memberWithRole(User::factory()->create(), 'viewer');
        $account = TwoFAccount::factory()->forUser($viewer)->create();
        SharedAccount::create([
            'team_id'        => $this->team->id,
            'twofaccount_id' => $account->id,
            'shared_by'      => $viewer->id,
            'access_level'   => 'read',
        ]);

        Passport::actingAs($viewer, ['legacy_full_access'], 'api-guard');

        $this->deleteJson('/api/v1/teams/' . $this->team->id . '/share/' . $account->id)
            ->assertStatus(200);
        $this->assertDatabaseMissing('shared_accounts', ['team_id' => $this->team->id, 'twofaccount_id' => $account->id]);
    }

    public function test_viewer_cannot_unshare_others_shares() : void
    {
        $viewer  = $this->memberWithRole(User::factory()->create(), 'viewer');
        $account = TwoFAccount::factory()->forUser($this->owner)->create();
        SharedAccount::create([
            'team_id'        => $this->team->id,
            'twofaccount_id' => $account->id,
            'shared_by'      => $this->owner->id,
            'access_level'   => 'read',
        ]);

        Passport::actingAs($viewer, ['legacy_full_access'], 'api-guard');

        $this->deleteJson('/api/v1/teams/' . $this->team->id . '/share/' . $account->id)
            ->assertStatus(403);
    }

    public function test_admin_can_still_not_export_activity() : void
    {
        $admin = $this->memberWithRole(User::factory()->create(), 'admin');

        Passport::actingAs($admin, ['legacy_full_access'], 'api-guard');

        $this->getJson('/api/v1/teams/' . $this->team->id . '/activity')
            ->assertStatus(200);

        $this->getJson('/api/v1/teams/' . $this->team->id . '/activity/export')
            ->assertStatus(403);
    }

    public function test_activity_view_permission_gates_the_activity_list() : void
    {
        $auditor = $this->memberWithRole(User::factory()->create(), 'auditor');
        $this->makeCustomRole('auditor', [TeamPermission::ACTIVITY_VIEW]);

        Passport::actingAs($auditor, ['legacy_full_access'], 'api-guard');

        $this->getJson('/api/v1/teams/' . $this->team->id . '/activity')
            ->assertStatus(200);
    }
}
