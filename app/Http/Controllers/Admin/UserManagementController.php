<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserManagementController extends Controller
{
    /**
     * List all users (admin only).
     */
    public function index(Request $request)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Forbidden - Admin access required'], 403);
        }

        $query = User::query()
            ->withCount(['teams', 'ownedTeams', 'twofaccounts'])
            ->orderBy('created_at', 'desc');

        // Filter by status
        if ($request->has('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        // Filter by role
        if ($request->has('role')) {
            if ($request->role === 'admin') {
                $query->where('is_admin', true);
            } elseif ($request->role === 'user') {
                $query->where('is_admin', false);
            }
        }

        $perPage = $request->input('per_page', 20);
        $users = $query->paginate($perPage);

        return response()->json($users);
    }

    /**
     * Show user details.
     */
    public function show(Request $request, $id)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Forbidden - Admin access required'], 403);
        }

        $user = User::withCount(['teams', 'ownedTeams', 'twofaccounts', 'groups'])
            ->with(['teams:id,name', 'ownedTeams:id,name'])
            ->findOrFail($id);

        return response()->json($user);
    }

    /**
     * Update user.
     */
    public function update(Request $request, $id)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Forbidden - Admin access required'], 403);
        }

        $user = User::findOrFail($id);
        
        // Prevent self-demotion if last admin
        if ($request->has('is_admin') && !$request->is_admin && $user->isLastAdministrator()) {
            return response()->json([
                'message' => 'Cannot demote the last administrator'
            ], 400);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'is_admin' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $user->update($validated);

        return response()->json($user);
    }

    /**
     * Deactivate user.
     */
    public function destroy(Request $request, $id)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Forbidden - Admin access required'], 403);
        }

        $user = User::findOrFail($id);

        // Prevent self-deactivation if last admin
        if ($user->isLastAdministrator()) {
            return response()->json([
                'message' => 'Cannot deactivate the last administrator'
            ], 400);
        }

        // Prevent deactivating self
        if ($user->id === Auth::id()) {
            return response()->json([
                'message' => 'Cannot deactivate yourself'
            ], 400);
        }

        $user->update(['is_active' => false]);

        return response()->json([
            'message' => 'User deactivated successfully',
            'user' => $user,
        ]);
    }
}
