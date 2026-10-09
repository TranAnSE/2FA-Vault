<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;
use App\Support\TeamPermission;

/**
 * Team authorization policy (v1.4.0 permission matrix).
 *
 * Every ability is a thin mapping onto the team's resolved permission set
 * (`Team::permissionsFor()`), EXCEPT the owner-locked abilities — team
 * deletion, ownership transfer and role management are hard-wired to
 * `$team->owner_id` and can never be granted by any matrix combination.
 * `view` stays a plain membership check.
 *
 * No global admin bypass exists (AppServiceProvider keeps it that way).
 */
class TeamPolicy
{
    /**
     * Determine whether the user can view the team (any member).
     */
    public function view(User $user, Team $team) : bool
    {
        return $team->hasMember($user->id);
    }

    /**
     * Determine whether the user can view the team activity log.
     */
    public function viewActivity(User $user, Team $team) : bool
    {
        return $team->permissionsFor($user)->has(TeamPermission::ACTIVITY_VIEW);
    }

    /**
     * Determine whether the user can export the team activity log.
     * Owner-locked (v1.3.x behavior: stricter than viewing).
     */
    public function exportActivity(User $user, Team $team) : bool
    {
        return $team->owner_id === $user->id;
    }

    /**
     * Determine whether the user can update the team.
     */
    public function update(User $user, Team $team) : bool
    {
        return $team->permissionsFor($user)->has(TeamPermission::TEAM_EDIT);
    }

    /**
     * Determine whether the user can delete the team. Owner-locked.
     */
    public function delete(User $user, Team $team) : bool
    {
        return $team->owner_id === $user->id;
    }

    /**
     * Determine whether the user can invite members to the team.
     */
    public function invite(User $user, Team $team) : bool
    {
        return $team->permissionsFor($user)->has(TeamPermission::MEMBERS_INVITE);
    }

    /**
     * Determine whether the user can remove members from the team.
     */
    public function removeMember(User $user, Team $team) : bool
    {
        return $team->permissionsFor($user)->has(TeamPermission::MEMBERS_REMOVE);
    }

    /**
     * Determine whether the user can update member roles (assign role slugs
     * that are subsets of their own effective permissions — the subset
     * constraint itself is enforced in TeamService::updateMemberRole).
     */
    public function updateRole(User $user, Team $team) : bool
    {
        return $team->permissionsFor($user)->has(TeamPermission::ROLES_ASSIGN);
    }

    /**
     * Determine whether the user can manage the team's role definitions
     * (create/edit/delete custom roles). Owner-locked.
     */
    public function manageRoles(User $user, Team $team) : bool
    {
        return $team->owner_id === $user->id;
    }

    /**
     * Determine whether the user can remove shares belonging to OTHER
     * members. Unsharing one's OWN share is a universal member rule, not a
     * permission (v1.3.x behavior, RT-8).
     */
    public function unshareOthers(User $user, Team $team) : bool
    {
        return $team->permissionsFor($user)->has(TeamPermission::ACCOUNTS_UNSHARE_OTHERS);
    }

    /**
     * Determine whether the user can transfer team ownership. Owner-locked.
     */
    public function transferOwnership(User $user, Team $team) : bool
    {
        return $team->owner_id === $user->id;
    }
}
