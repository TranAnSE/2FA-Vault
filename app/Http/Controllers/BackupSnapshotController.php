<?php

namespace App\Http\Controllers;

use App\Exceptions\RestoreStateChangedException;
use App\Exceptions\RestoreTokenInvalidException;
use App\Exceptions\SnapshotsUnreadableException;
use App\Http\Resources\BackupSnapshotResource;
use App\Models\BackupSnapshot;
use App\Services\BackupSnapshotService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
    public function __construct(protected BackupSnapshotService $snapshots) {}

    /** List the caller's snapshots (metadata only — no GET /{id}, RT-15). */
    public function index(Request $request) : JsonResponse
    {
        $snapshots = BackupSnapshot::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(BackupSnapshotResource::collection($snapshots));
    }

    /** Create a manual snapshot (rate limited like export). */
    public function store(Request $request) : JsonResponse
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
    public function destroy(BackupSnapshot $snapshot) : JsonResponse
    {
        if ($snapshot->user_id !== Auth::id()) {
            // 404, not 403 — avoid existence enumeration.
            throw (new ModelNotFoundException)->setModel(BackupSnapshot::class, [$snapshot->id]);
        }

        $this->snapshots->deleteSnapshot($snapshot);

        Log::info(sprintf('User ID #%s deleted snapshot #%s', Auth::id(), $snapshot->id));

        return response()->json(null, 204);
    }

    /**
     * Dry-run: what a restore would create/update/delete. No mutation; issues
     * the confirmation token the restore call must present (replace mode is
     * impossible without this step — the token is the gate, the typed
     * RESTORE string is UX reinforcement).
     */
    public function dryRun(Request $request, BackupSnapshot $snapshot) : JsonResponse
    {
        if ($snapshot->user_id !== Auth::id()) {
            throw (new ModelNotFoundException)->setModel(BackupSnapshot::class, [$snapshot->id]);
        }

        if (! app()->environment('testing')) {
            $key = 'snapshot-dryrun:' . $request->user()->id;
            if (RateLimiter::tooManyAttempts($key, 10)) {
                $seconds = RateLimiter::availableIn($key);

                return response()->json([
                    'message' => 'Too many dry-run attempts. Please try again in ' . ceil($seconds / 60) . ' minutes.',
                ], 429);
            }
            RateLimiter::hit($key, 3600);
        }

        $validated = $request->validate([
            'mode' => 'required|in:merge,replace',
        ]);

        try {
            $diff = $this->snapshots->dryRun($request->user(), $snapshot, $validated['mode']);
        } catch (SnapshotsUnreadableException $e) {
            return response()->json(['unreadable_reason' => $e->reason], 422);
        }

        return response()->json($diff);
    }

    /**
     * Restore a snapshot. merge = non-destructive (diff + simple confirm);
     * replace = requires the dry-run token AND the typed `RESTORE` string,
     * auto-creates a pre_restore safety snapshot, and runs as one
     * all-or-nothing transaction.
     */
    public function restore(Request $request, BackupSnapshot $snapshot) : JsonResponse
    {
        if ($snapshot->user_id !== Auth::id()) {
            throw (new ModelNotFoundException)->setModel(BackupSnapshot::class, [$snapshot->id]);
        }

        if (! app()->environment('testing')) {
            $key = 'snapshot-restore:' . $request->user()->id;
            if (RateLimiter::tooManyAttempts($key, 3)) {
                $seconds = RateLimiter::availableIn($key);

                return response()->json([
                    'message' => 'Too many restore attempts. Please try again in ' . ceil($seconds / 60) . ' minutes.',
                ], 429);
            }
            RateLimiter::hit($key, 3600);
        }

        $validated = $request->validate([
            'mode'    => 'required|in:merge,replace',
            'token'   => 'required|string',
            'confirm' => 'nullable|string',
        ]);

        try {
            $result = $this->snapshots->restore(
                $request->user(),
                $snapshot,
                $validated['mode'],
                $validated['token'],
                $validated['confirm'] ?? null,
            );
        } catch (SnapshotsUnreadableException $e) {
            return response()->json(['unreadable_reason' => $e->reason], 422);
        } catch (RestoreTokenInvalidException $e) {
            return response()->json(['code' => 'restore_token_invalid', 'message' => $e->getMessage()], 422);
        } catch (RestoreStateChangedException $e) {
            return response()->json(['code' => 'restore_state_changed', 'message' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // The service writes the single summary audit entry; this is the
        // request log only.
        Log::info(sprintf(
            'User ID #%s restored snapshot #%s (mode=%s, +%d/~%d/-%d)',
            $request->user()->id,
            $snapshot->id,
            $validated['mode'],
            $result['created_count'],
            $result['updated_count'],
            $result['deleted_count'],
        ));

        return response()->json($result);
    }
}
