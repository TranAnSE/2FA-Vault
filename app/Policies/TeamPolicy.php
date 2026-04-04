<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Determine whether the user can view the team.
     */
    public function view(User $user, Team $team): bool
    {
        return $team->hasMember($user->id);
    }

    /**
     * Determine whether the user can update the team.
     */
    public function update(User $user, Team $team): bool
    {
        $role = $team->getUserRole($user->id);
        return in_array($role, ['admin', 'owner']);
    }

    /**
     * Determine whether the user can delete the team.
     */
    public function delete(User $user, Team $team): bool
    {
        return $team->owner_id === $user->id;
    }

    /**
     * Determine whether the user can invite members to the team.
     */
    public function invite(User $user, Team $team): bool
    {
        $role = $team->getUserRole($user->id);
        return in_array($role, ['admin', 'owner']);
    }

    /**
     * Determine whether the user can remove members from the team.
     */
    public function removeMember(User $user, Team $team): bool
    {
        $role = $team->getUserRole($user->id);
        return in_array($role, ['admin', 'owner']);
    }

    /**
     * Determine whether the user can update member roles.
     */
    public function updateRole(User $user, Team $team): bool
    {
        return $team->owner_id === $user->id;
    }
}
