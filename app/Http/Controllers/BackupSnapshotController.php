<?php

namespace App\Http\Controllers;

use App\Http\Resources\BackupSnapshotResource;
use App\Models\BackupSnapshot;
use App\Services\BackupSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Backup snapshot lifecycle endpoints: list / manual create / delete.
 * Restore (dry-run + restore) lives here too from phase 4.
 * All queries are owner-scoped; other users' snapshots 404 (no enumeration).
 */
class BackupSnapshotController extends Controller
{
    public function __construct(protected BackupSnapshotService $snapshots)
    {
    }

    /** List the caller's snapshots (metadata only — no GET /{id}, RT-15). */
    public function index(Request $request): JsonResponse
    {
        $snapshots = BackupSnapshot::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(BackupSnapshotResource::collection($snapshots));
    }

    /** Create a manual snapshot (rate limited like export). */
    public function store(Request $request): JsonResponse
    {
        // Rate limiting: max 5 manual creates per hour (mirrors export).
        if (! app()->environment('testing')) {
            $key = 'snapshot-create:' . $request->user()->id;

            if (RateLimiter::tooManyAttempts($key, 5)) {
                $seconds = RateLimiter::availableIn($key);

                return response()->json([
                    'message' => 'Too many snapshot attempts. Please try again in ' . ceil($seconds / 60) . ' minutes.',
                ], 429);
            }

            RateLimiter::hit($key, 3600);
        }

        $validated = $request->validate([
            'label' => 'nullable|string|max:100',
        ]);

        $snapshot = $this->snapshots->createSnapshot(
            $request->user(),
            BackupSnapshotService::SOURCE_MANUAL,
            $validated['label'] ?? null,
        );

        Log::info(sprintf('User ID #%s created manual snapshot #%s', $request->user()->id, $snapshot->id));

        return response()->json(new BackupSnapshotResource($snapshot), 201);
    }

    /** Delete a snapshot (manual cleanup). */
    public function destroy(BackupSnapshot $snapshot): JsonResponse
    {
        if ($snapshot->user_id !== Auth::id()) {
            // 404, not 403 — avoid existence enumeration.
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(BackupSnapshot::class, [$snapshot->id]);
        }

        $this->snapshots->deleteSnapshot($snapshot);

        Log::info(sprintf('User ID #%s deleted snapshot #%s', Auth::id(), $snapshot->id));

        return response()->json(null, 204);
    }
}
