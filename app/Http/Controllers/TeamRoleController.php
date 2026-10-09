<?php

namespace App\Http\Controllers;

use App\Enums\TeamAction;
use App\Http\Resources\TeamRoleResource;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamRole;
use App\Services\TeamActivityLogger;
use App\Support\TeamPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Per-team custom role CRUD (v1.4.0 permission matrix).
 *
 * All mutations are owner-only (the `roles.manage` owner-locked policy rule)
 * — a custom role can never mint or edit roles without owning the team, so
 * no role-combination can escalate. Assignment reuses
 * TeamController::updateMemberRole (roles.assign + subset constraint).
 */
class TeamRoleController extends Controller
{
    public function __construct(private readonly TeamActivityLogger $activityLogger) {}

    /**
     * List the team's roles (any member — the client's source for
     * permission-derived UI gating).
     */
    public function index(Request $request, int $id) : JsonResponse
    {
        $team = Team::findOrFail($id);

        if (! Gate::allows('view', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $memberCounts = $team->users()
            ->selectRaw('team_users.role as slug, count(*) as members_count')
            ->groupBy('team_users.role')
            ->pluck('members_count', 'slug');

        $roles = $team->roles()
            ->orderByDesc('is_system')
            ->orderBy('slug')
            ->get()
            ->map(fn (TeamRole $role) => [
                'id'            => $role->id,
                'slug'          => $role->slug,
                'name'          => $role->name,
                'permissions'   => $role->permissions ?? [],
                'is_system'     => (bool) $role->is_system,
                'members_count' => (int) ($memberCounts[$role->slug] ?? 0),
            ]);

        return response()->json($roles);
    }

    /**
     * Create a custom role (owner only).
     */
    public function store(Request $request, int $id) : JsonResponse
    {
        $team = Team::findOrFail($id);

        if (! Gate::allows('manageRoles', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $this->validateRolePayload($request, $team);

        $role = TeamRole::create([
            'team_id'     => $team->id,
            'slug'        => $validated['slug'],
            'name'        => $validated['name'],
            'permissions' => $validated['permissions'],
            'is_system'   => false,
        ]);

        $this->activityLogger->log($team, Auth::user(), TeamAction::ROLE_CREATED, [
            'slug'        => $role->slug,
            'permissions' => $role->permissions,
        ]);

        return response()->json((new TeamRoleResource($role))->toArray($request), 201);
    }

    /**
     * Update a custom role (owner only). System presets are the
     * behavior-compat contract and cannot be edited.
     */
    public function update(Request $request, int $id, int $roleId) : JsonResponse
    {
        $team = Team::findOrFail($id);

        if (! Gate::allows('manageRoles', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $role = $team->roles()->findOrFail($roleId);

        if ($role->is_system) {
            return response()->json(['message' => 'System roles cannot be modified.'], 422);
        }

        $validated = $this->validateRolePayload($request, $team, $role);

        if (($validated['slug'] ?? null) !== null && $validated['slug'] !== $role->slug) {
            // Slugs are referenced by member pivots and invitations — immutable.
            return response()->json(['message' => 'The role slug is immutable.'], 422);
        }

        $role->update([
            'name'        => $validated['name'],
            'permissions' => $validated['permissions'],
        ]);

        $this->activityLogger->log($team, Auth::user(), TeamAction::ROLE_UPDATED, [
            'slug'        => $role->slug,
            'permissions' => $role->permissions,
        ]);

        return response()->json((new TeamRoleResource($role->fresh()))->toArray($request));
    }

    /**
     * Delete a custom role (owner only). Blocked while the role is still
     * assigned to members or targeted by pending invitations (RT-15) —
     * invitations carry the slug verbatim on accept, so deletion would
     * strand them (Phase 6's fail-closed permissionsFor is the backstop,
     * not the fix). System presets are always blocked.
     */
    public function destroy(Request $request, int $id, int $roleId) : JsonResponse
    {
        $team = Team::findOrFail($id);

        if (! Gate::allows('manageRoles', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $role = $team->roles()->findOrFail($roleId);

        if ($role->is_system) {
            return response()->json(['message' => 'System roles cannot be deleted.'], 422);
        }

        $assignedMembers = $team->users()->where('team_users.role', $role->slug)->count();
        if ($assignedMembers > 0) {
            return response()->json([
                'message'       => 'This role is still assigned to ' . $assignedMembers . ' member(s). Reassign them first.',
                'members_count' => $assignedMembers,
            ], 422);
        }

        $pendingInvitations = TeamInvitation::where('team_id', $team->id)
            ->where('role', $role->slug)
            ->where('status', 'pending')
            ->count();
        if ($pendingInvitations > 0) {
            return response()->json([
                'message'           => 'This role is targeted by ' . $pendingInvitations . ' pending invitation(s). Cancel them first.',
                'invitations_count' => $pendingInvitations,
            ], 422);
        }

        $role->delete();

        $this->activityLogger->log($team, Auth::user(), TeamAction::ROLE_DELETED, ['slug' => $role->slug]);

        return response()->json([], 204);
    }

    /**
     * Shared validation: slug format + uniqueness (reserved system slugs
     * blocked), name length, permissions ⊆ closed catalog (reject unknown —
     * no privilege escalation via crafted payloads), at least one permission.
     *
     * @return array{slug?: string, name: string, permissions: list<string>}
     */
    private function validateRolePayload(Request $request, Team $team, ?TeamRole $existing = null) : array
    {
        $reservedSlugs = array_keys(TeamPermission::systemPresets());

        $validated = $request->validate([
            'slug' => [
                'sometimes',
                'required',
                'regex:/^[a-z0-9-]{2,30}$/',
                Rule::notIn($reservedSlugs),
                Rule::unique('team_roles', 'slug')->where('team_id', $team->id)->ignore($existing?->id),
            ],
            'name'          => ['required', 'string', 'max:50'],
            'permissions'   => ['required', 'array', 'min:1'],
            'permissions.*' => [Rule::in(TeamPermission::all())],
        ]);

        // The effective set must never contain unknown duplicates.
        $validated['permissions'] = array_values(array_unique($validated['permissions']));
        sort($validated['permissions']);

        return $validated;
    }
}
