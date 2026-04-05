<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\TwoFAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * TeamService
 *
 * Business logic layer for team management.
 * Handles team CRUD, member management, invitations, and shared accounts.
 */
class TeamService
{
    /**
     * Create a new team
     *
     * @param User $owner
     * @param string $name
     * @return Team
     * @throws \Exception
     */
    public function createTeam(User $owner, string $name): Team
    {
        $maxTeams = config('2fauth.maxTeamsPerUser', 10);
        $userTeamsCount = Team::accessibleByUser($owner->id)->count();

        if ($userTeamsCount >= $maxTeams) {
            throw new \Exception("You have reached the maximum number of teams ({$maxTeams}).");
        }

        $team = Team::create([
            'name' => $name,
            'owner_id' => $owner->id,
        ]);

        // Add owner as team member
        $team->users()->attach($owner->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        Log::info('Team created', [
            'team_id' => $team->id,
            'owner_id' => $owner->id,
            'name' => $name,
        ]);

        return $team;
    }

    /**
     * Update team name
     *
     * @param Team $team
     * @param User $user
     * @param string $name
     * @return Team
     * @throws \Exception
     */
    public function updateTeam(Team $team, User $user, string $name): Team
    {
        if (!$this->canUpdateTeam($team, $user)) {
            throw new \Exception('You do not have permission to update this team.');
        }

        $team->update(['name' => $name]);

        Log::info('Team updated', [
            'team_id' => $team->id,
            'updated_by' => $user->id,
            'name' => $name,
        ]);

        return $team->refresh();
    }

    /**
     * Delete a team (soft delete)
     *
     * @param Team $team
     * @param User $user
     * @return bool
     * @throws \Exception
     */
    public function deleteTeam(Team $team, User $user): bool
    {
        if ($team->owner_id !== $user->id) {
            throw new \Exception('Only the team owner can delete the team.');
        }

        $team->delete();

        Log::info('Team deleted', [
            'team_id' => $team->id,
            'deleted_by' => $user->id,
        ]);

        return true;
    }

    /**
     * Invite a user to join a team
     *
     * @param Team $team
     * @param User $inviter
     * @param string $email
     * @param string $role
     * @return TeamInvitation
     * @throws \Exception
     */
    public function inviteUser(Team $team, User $inviter, string $email, string $role = 'member'): TeamInvitation
    {
        if (!$this->canInviteUsers($team, $inviter)) {
            throw new \Exception('You do not have permission to invite users to this team.');
        }

        if (!in_array($role, ['admin', 'member', 'viewer'])) {
            throw new \Exception('Invalid role specified.');
        }

        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'email' => $email,
            'role' => $role,
            'token' => Str::random(32),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        Log::info('Team invitation created', [
            'team_id' => $team->id,
            'invitation_id' => $invitation->id,
            'email' => $email,
            'role' => $role,
            'invited_by' => $inviter->id,
        ]);

        return $invitation;
    }

    /**
     * Accept a team invitation
     *
     * @param TeamInvitation $invitation
     * @param User $user
     * @return Team
     * @throws \Exception
     */
    public function acceptInvitation(TeamInvitation $invitation, User $user): Team
    {
        if ($invitation->email !== $user->email) {
            throw new \Exception('This invitation is not for your email address.');
        }

        if ($invitation->status !== 'pending') {
            throw new \Exception('This invitation is no longer valid.');
        }

        if ($invitation->isExpired()) {
            throw new \Exception('This invitation has expired.');
        }

        $team = Team::findOrFail($invitation->team_id);

        if ($team->hasMember($user->id)) {
            throw new \Exception('You are already a member of this team.');
        }

        $maxMembers = config('2fauth.maxMembersPerTeam', 50);
        if ($team->users()->count() >= $maxMembers) {
            throw new \Exception("This team has reached the maximum number of members ({$maxMembers}).");
        }

        DB::beginTransaction();

        try {
            // Add user to team
            $team->users()->attach($user->id, [
                'role' => $invitation->role,
                'joined_at' => now(),
            ]);

            // Update invitation status
            $invitation->update(['status' => 'accepted']);

            DB::commit();

            Log::info('Team invitation accepted', [
                'team_id' => $team->id,
                'invitation_id' => $invitation->id,
                'user_id' => $user->id,
            ]);

            return $team;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Join a team via invite code
     *
     * @param string $inviteCode
     * @param User $user
     * @return Team
     * @throws \Exception
     */
    public function joinByInviteCode(string $inviteCode, User $user): Team
    {
        $team = Team::where('invite_code', $inviteCode)->firstOrFail();

        if ($team->hasMember($user->id)) {
            throw new \Exception('You are already a member of this team.');
        }

        $maxMembers = config('2fauth.maxMembersPerTeam', 50);
        if ($team->users()->count() >= $maxMembers) {
            throw new \Exception("This team has reached the maximum number of members ({$maxMembers}).");
        }

        $team->users()->attach($user->id, [
            'role' => 'member',
            'joined_at' => now(),
        ]);

        Log::info('User joined team via invite code', [
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        return $team;
    }

    /**
     * Leave a team
     *
     * @param Team $team
     * @param User $user
     * @return bool
     * @throws \Exception
     */
    public function leaveTeam(Team $team, User $user): bool
    {
        if ($team->owner_id === $user->id) {
            throw new \Exception('Team owner cannot leave. Transfer ownership or delete the team instead.');
        }

        $team->users()->detach($user->id);

        Log::info('User left team', [
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        return true;
    }

    /**
     * Remove a member from the team
     *
     * @param Team $team
     * @param User $actor
     * @param int $userIdToRemove
     * @return bool
     * @throws \Exception
     */
    public function removeMember(Team $team, User $actor, int $userIdToRemove): bool
    {
        if (!$this->canRemoveMembers($team, $actor)) {
            throw new \Exception('You do not have permission to remove members from this team.');
        }

        if ($team->owner_id == $userIdToRemove) {
            throw new \Exception('Cannot remove team owner.');
        }

        $team->users()->detach($userIdToRemove);

        Log::info('Member removed from team', [
            'team_id' => $team->id,
            'removed_user_id' => $userIdToRemove,
            'removed_by' => $actor->id,
        ]);

        return true;
    }

    /**
     * Update member role
     *
     * @param Team $team
     * @param User $actor
     * @param int $targetUserId
     * @param string $newRole
     * @return bool
     * @throws \Exception
     */
    public function updateMemberRole(Team $team, User $actor, int $targetUserId, string $newRole): bool
    {
        if ($team->owner_id !== $actor->id) {
            throw new \Exception('Only the team owner can update member roles.');
        }

        if (!in_array($newRole, ['admin', 'member', 'viewer'])) {
            throw new \Exception('Invalid role specified.');
        }

        if ($team->owner_id == $targetUserId) {
            throw new \Exception('Cannot change owner role.');
        }

        $team->users()->updateExistingPivot($targetUserId, [
            'role' => $newRole,
        ]);

        Log::info('Member role updated', [
            'team_id' => $team->id,
            'target_user_id' => $targetUserId,
            'new_role' => $newRole,
            'updated_by' => $actor->id,
        ]);

        return true;
    }

    /**
     * Share an account with a team
     *
     * @param TwoFAccount $account
     * @param Team $team
     * @param User $sharer
     * @param string $accessLevel
     * @return \App\Models\SharedAccount
     * @throws \Exception
     */
    public function shareAccountWithTeam(TwoFAccount $account, Team $team, User $sharer, string $accessLevel = 'view'): \App\Models\SharedAccount
    {
        if ($account->user_id !== $sharer->id) {
            throw new \Exception('You can only share accounts you own.');
        }

        if (!$team->hasMember($sharer->id)) {
            throw new \Exception('You must be a member of the team to share accounts with it.');
        }

        $sharedAccount = \App\Models\SharedAccount::create([
            'team_id' => $team->id,
            'twofaccount_id' => $account->id,
            'shared_by' => $sharer->id,
            'access_level' => $accessLevel,
        ]);

        Log::info('Account shared with team', [
            'account_id' => $account->id,
            'team_id' => $team->id,
            'shared_by' => $sharer->id,
            'access_level' => $accessLevel,
        ]);

        return $sharedAccount;
    }

    /**
     * Check if user can update team
     */
    private function canUpdateTeam(Team $team, User $user): bool
    {
        $role = $team->getUserRole($user->id);
        return in_array($role, ['admin', 'owner']);
    }

    /**
     * Check if user can invite others
     */
    private function canInviteUsers(Team $team, User $user): bool
    {
        $role = $team->getUserRole($user->id);
        return in_array($role, ['admin', 'owner']);
    }

    /**
     * Check if user can remove members
     */
    private function canRemoveMembers(Team $team, User $user): bool
    {
        $role = $team->getUserRole($user->id);
        return in_array($role, ['admin', 'owner']);
    }

    /**
     * Get team statistics
     *
     * @param Team $team
     * @return array
     */
    public function getTeamStats(Team $team): array
    {
        return [
            'members_count' => $team->users()->count(),
            'shared_accounts_count' => $team->sharedAccounts()->count(),
            'invitations_pending' => TeamInvitation::where('team_id', $team->id)
                ->where('status', 'pending')
                ->count(),
        ];
    }
}
