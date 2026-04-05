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
}
