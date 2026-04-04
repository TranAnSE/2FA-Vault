<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
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
                return [
                    'id' => $team->id,
                    'name' => $team->name,
                    'owner_id' => $team->owner_id,
                    'owner_name' => $team->owner->name,
                    'role' => $team->getUserRole($user->id),
                    'members_count' => $team->users_count,
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
        $maxTeams = config('2fauth.maxTeamsPerUser', 10);
        $user = Auth::user();
        
        // Check team limit
        $userTeamsCount = Team::accessibleByUser($user->id)->count();
        if ($userTeamsCount >= $maxTeams) {
            return response()->json([
                'message' => "You have reached the maximum number of teams ({$maxTeams})."
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $team = Team::create([
            'name' => $validated['name'],
            'owner_id' => $user->id,
        ]);

        // Add owner to team_users as well
        $team->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return response()->json($team, 201);
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

        return response()->json([
            'id' => $team->id,
            'name' => $team->name,
            'owner_id' => $team->owner_id,
            'owner_name' => $team->owner->name,
            'invite_code' => Gate::allows('invite', $team) ? $team->invite_code : null,
            'members' => $members,
            'created_at' => $team->created_at,
        ]);
    }

    /**
     * Update team name.
     */
    public function update(Request $request, $id)
    {
        $team = Team::findOrFail($id);

        if (!Gate::allows('update', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $team->update($validated);

        return response()->json($team);
    }

    /**
     * Delete a team (soft delete).
     */
    public function destroy(Request $request, $id)
    {
        $team = Team::findOrFail($id);

        if (!Gate::allows('delete', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $team->delete();

        return response()->json(['message' => 'Team deleted successfully']);
    }

    /**
     * Generate or regenerate invite code.
     */
    public function invite(Request $request, $id)
    {
        $team = Team::findOrFail($id);

        if (!Gate::allows('invite', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $inviteCode = $team->generateInviteCode();

        return response()->json([
            'invite_code' => $inviteCode,
            'invite_url' => route('teams.join', ['code' => $inviteCode]),
        ]);
    }

    /**
     * Join a team via invite code.
     */
    public function join(Request $request)
    {
        $validated = $request->validate([
            'invite_code' => 'required|string|exists:teams,invite_code',
        ]);

        $team = Team::where('invite_code', $validated['invite_code'])->firstOrFail();
        $user = Auth::user();

        // Check if already a member
        if ($team->hasMember($user->id)) {
            return response()->json(['message' => 'You are already a member of this team'], 400);
        }

        // Check team size limit
        $maxMembers = config('2fauth.maxMembersPerTeam', 50);
        if ($team->users()->count() >= $maxMembers) {
            return response()->json([
                'message' => "This team has reached the maximum number of members ({$maxMembers})."
            ], 403);
        }

        $team->users()->attach($user->id, [
            'role' => 'member',
            'joined_at' => now(),
        ]);

        return response()->json([
            'message' => 'Successfully joined team',
            'team' => $team,
        ]);
    }

    /**
     * Leave a team.
     */
    public function leave(Request $request, $id)
    {
        $team = Team::findOrFail($id);
        $user = Auth::user();

        // Owner cannot leave
        if ($team->owner_id === $user->id) {
            return response()->json([
                'message' => 'Team owner cannot leave. Transfer ownership or delete the team instead.'
            ], 400);
        }

        $team->users()->detach($user->id);

        return response()->json(['message' => 'Successfully left the team']);
    }

    /**
     * Remove a member from the team.
     */
    public function removeMember(Request $request, $id, $userId)
    {
        $team = Team::findOrFail($id);

        if (!Gate::allows('removeMember', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Cannot remove owner
        if ($team->owner_id == $userId) {
            return response()->json(['message' => 'Cannot remove team owner'], 400);
        }

        $team->users()->detach($userId);

        return response()->json(['message' => 'Member removed successfully']);
    }

    /**
     * Update member role.
     */
    public function updateMemberRole(Request $request, $id, $userId)
    {
        $team = Team::findOrFail($id);

        if (!Gate::allows('updateRole', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'role' => ['required', Rule::in(['admin', 'member', 'viewer'])],
        ]);

        // Cannot change owner's role
        if ($team->owner_id == $userId) {
            return response()->json(['message' => 'Cannot change owner role'], 400);
        }

        $team->users()->updateExistingPivot($userId, [
            'role' => $validated['role'],
        ]);

        return response()->json(['message' => 'Member role updated successfully']);
    }
}
