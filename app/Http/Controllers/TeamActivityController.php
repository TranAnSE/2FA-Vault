<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class TeamActivityController extends Controller
{
    /**
     * List activity log entries for a team (owner/admin only).
     */
    public function index(Request $request, int $id): JsonResponse
    {
        $team = Team::findOrFail($id);

        if (!Gate::allows('view', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $role = $team->getUserRole(Auth::id());
        if (!in_array($role, ['owner', 'admin'])) {
            return response()->json(['message' => 'Forbidden — only team owners and admins can view activity logs'], 403);
        }

        $query = $team->activityLogs()
            ->with(['user:id,name,email', 'targetUser:id,name,email', 'targetAccount:id,service,account']);

        if ($request->filled('actions')) {
            $query->whereIn('action', explode(',', $request->actions));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->to);
        }

        $logs = $query->orderBy('created_at', 'desc')->paginate(50);

        return response()->json($logs);
    }

    /**
     * Export activity log as JSON (owner only).
     */
    public function export(Request $request, int $id)
    {
        $team = Team::findOrFail($id);

        if ($team->getUserRole(Auth::id()) !== 'owner') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $logs = $team->activityLogs()
            ->with(['user:id,name,email', 'targetUser:id,name,email', 'targetAccount:id,service,account'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();

        $filename = "team-{$team->id}-activity-" . now()->format('Y-m-d') . '.json';

        return response()->streamDownload(
            fn () => print json_encode(['team' => $team->name, 'exported_at' => now()->toISOString(), 'logs' => $logs], JSON_PRETTY_PRINT),
            $filename,
            ['Content-Type' => 'application/json']
        );
    }
}
