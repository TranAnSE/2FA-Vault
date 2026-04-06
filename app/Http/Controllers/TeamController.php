<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\TeamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    protected TeamService $teamService;

    public function __construct(TeamService $teamService)
    {
        $this->teamService = $teamService;
    }

    /**
     * List all teams for the authenticated user.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $teams = Team::accessibleByUser($user->id)
            ->with(['owner', 'users'])
            ->withCount('users')
            ->get()
            ->map(function ($team) use ($user) {
                $stats = $this->teamService->getTeamStats($team);

                return [
                    'id' => $team->id,
                    'name' => $team->name,
                    'owner_id' => $team->owner_id,
                    'owner_name' => $team->owner->name,
                    'role' => $team->getUserRole($user->id),
                    'members_count' => $team->users_count,
                    'shared_accounts_count' => $stats['shared_accounts_count'],
                    'pending_invitations' => $stats['invitations_pending'],
                    'created_at' => $team->created_at,
                    'invite_code' => $team->owner_id === $user->id ? $team->invite_code : null,
                ];
            });

        return response()->json($teams);
    }

    /**
     * Create a new team.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = Auth::user();

        try {
            $team = $this->teamService->createTeam($user, $validated['name']);

            return response()->json($team, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Show team details with members.
     */
    public function show(Request $request, $id)
    {
        $team = Team::with(['owner', 'users'])->findOrFail($id);

        if (!Gate::allows('view', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $members = $team->users->map(function ($user) use ($team) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $team->getUserRole($user->id),
                'joined_at' => $user->pivot->joined_at,
            ];
        });

        $stats = $this->teamService->getTeamStats($team);

        return response()->json([
            'id' => $team->id,
            'name' => $team->name,
            'owner_id' => $team->owner_id,
            'owner_name' => $team->owner->name,
            'invite_code' => Gate::allows('invite', $team) ? $team->invite_code : null,
            'members' => $members,
            'stats' => $stats,
            'created_at' => $team->created_at,
        ]);
    }

    /**
     * Update team name.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $team = Team::findOrFail($id);
        $user = Auth::user();

        try {
            $team = $this->teamService->updateTeam($team, $user, $validated['name']);

            return response()->json($team);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Delete a team (soft delete).
     */
    public function destroy(Request $request, $id)
    {
        $team = Team::findOrFail($id);
        $user = Auth::user();

        try {
            $this->teamService->deleteTeam($team, $user);

            return response()->json(['message' => 'Team deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Invite a user to the team.
     */
    public function invite(Request $request, $id)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'role' => ['nullable', Rule::in(['admin', 'member', 'viewer'])],
        ]);

        $team = Team::findOrFail($id);
        $user = Auth::user();

        try {
            $invitation = $this->teamService->inviteUser(
                $team,
                $user,
                $validated['email'],
                $validated['role'] ?? 'member'
            );

            return response()->json([
                'message' => 'Invitation sent successfully',
                'invitation' => $invitation,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Accept team invitation.
     */
    public function acceptInvitation(Request $request, $token)
    {
        $invitation = \App\Models\TeamInvitation::where('token', $token)
            ->where('status', 'pending')
            ->firstOrFail();

        $user = Auth::user();

        try {
            $team = $this->teamService->acceptInvitation($invitation, $user);

            return response()->json([
                'message' => 'Successfully joined team',
                'team' => $team,
            ]);
        } catch (\Exception $e) {
            $statusCode = $e->getMessage() === 'This invitation is not for your email address' ? 403 : 400;
            return response()->json(['message' => $e->getMessage()], $statusCode);
        }
    }

    /**
     * List pending invitations for a team.
     */
    public function invitations(Request $request, $id)
    {
        $team = Team::findOrFail($id);

        if (!Gate::allows('invite', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $invitations = \App\Models\TeamInvitation::where('team_id', $team->id)
            ->where('status', 'pending')
            ->get()
            ->map(function ($inv) {
                return [
                    'id' => $inv->id,
                    'email' => $inv->email,
                    'role' => $inv->role,
                    'status' => $inv->status,
                    'expires_at' => $inv->expires_at,
                    'created_at' => $inv->created_at,
                ];
            });

        return response()->json($invitations);
    }

    /**
     * Cancel a pending invitation.
     */
    public function cancelInvitation(Request $request, $id, $invitationId)
    {
        $team = Team::findOrFail($id);

        if (!Gate::allows('invite', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $invitation = \App\Models\TeamInvitation::where('id', $invitationId)
            ->where('team_id', $team->id)
            ->where('status', 'pending')
            ->firstOrFail();

        $invitation->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Invitation cancelled']);
    }

    /**
     * Join a team via invite code.
     */
    public function join(Request $request)
    {
        $validated = $request->validate([
            'invite_code' => 'required|string|exists:teams,invite_code',
        ]);

        $user = Auth::user();

        try {
            $team = $this->teamService->joinByInviteCode($validated['invite_code'], $user);

            return response()->json([
                'message' => 'Successfully joined team',
                'team' => $team,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Leave a team.
     */
    public function leave(Request $request, $id)
    {
        $team = Team::findOrFail($id);
        $user = Auth::user();

        try {
            $this->teamService->leaveTeam($team, $user);

            return response()->json(['message' => 'Successfully left the team']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Remove a member from the team.
     */
    public function removeMember(Request $request, $id, $userId)
    {
        $team = Team::findOrFail($id);
        $user = Auth::user();

        try {
            $this->teamService->removeMember($team, $user, $userId);

            return response()->json(['message' => 'Member removed successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Update member role.
     */
    public function updateMemberRole(Request $request, $id, $userId)
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(['admin', 'member', 'viewer'])],
        ]);

        $team = Team::findOrFail($id);
        $user = Auth::user();

        try {
            $this->teamService->updateMemberRole($team, $user, $userId, $validated['role']);

            return response()->json(['message' => 'Member role updated successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Share an account with a team.
     */
    public function shareAccount(Request $request, $id)
    {
        $validated = $request->validate([
            'twofaccount_id' => 'required|integer|exists:twofaccounts,id',
            'access_level' => 'nullable|in:read,write,admin',
        ]);

        $team = Team::findOrFail($id);
        $user = Auth::user();

        if (!$team->hasMember($user->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $account = \App\Models\TwoFAccount::where('id', $validated['twofaccount_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        try {
            $sharedAccount = $this->teamService->shareAccountWithTeam(
                $account,
                $team,
                $user,
                $validated['access_level'] ?? 'read'
            );

            return response()->json([
                'message' => 'Account shared with team successfully',
                'shared_account' => $sharedAccount,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Unshare an account from a team.
     */
    public function unshareAccount(Request $request, $id, $accountId)
    {
        $team = Team::findOrFail($id);
        $user = Auth::user();

        $sharedAccount = \App\Models\SharedAccount::where('team_id', $team->id)
            ->where('twofaccount_id', $accountId)
            ->firstOrFail();

        if ($sharedAccount->shared_by !== $user->id) {
            $role = $team->getUserRole($user->id);
            if (!in_array($role, ['owner', 'admin'])) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $sharedAccount->delete();

        return response()->json(['message' => 'Account unshared successfully']);
    }

    /**
     * List shared accounts for a team.
     */
    public function sharedAccounts(Request $request, $id)
    {
        $team = Team::findOrFail($id);

        if (!Gate::allows('view', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $sharedAccounts = $team->sharedAccounts()
            ->with(['twoFAccount', 'sharedBy'])
            ->get()
            ->map(function ($sa) {
                return [
                    'id' => $sa->id,
                    'twofaccount_id' => $sa->twofaccount_id,
                    'account_service' => $sa->twoFAccount->service ?? '',
                    'account_name' => $sa->twoFAccount->account ?? '',
                    'shared_by' => $sa->sharedBy->name,
                    'shared_by_id' => $sa->shared_by,
                    'access_level' => $sa->access_level,
                    'created_at' => $sa->created_at,
                ];
            });

        return response()->json($sharedAccounts);
    }
}
