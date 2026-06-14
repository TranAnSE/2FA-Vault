<?php

namespace App\Api\v1\Controllers;

use App\Api\v1\Resources\UserSessionResource;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class UserSessionController extends Controller
{
    /**
     * Display a listing of the user's active sessions.
     *
     * @param  Request  $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $sessions = $user->sessions()
            ->join('oauth_access_tokens', 'user_sessions.token_id', '=', 'oauth_access_tokens.id')
            ->where('oauth_access_tokens.revoked', false)
            ->select('user_sessions.*')
            ->latest('user_sessions.last_active_at')
            ->paginate(50);

        return UserSessionResource::collection($sessions);
    }

    /**
     * Revoke a specific user session.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return JsonResponse
     */
    public function destroy(Request $request, $id)
    {
        /** @var User $user */
        $user = $request->user();

        $session = $user->sessions()->findOrFail($id);

        // Get the associated passport token and revoke it
        $token = DB::table('oauth_access_tokens')
            ->where('id', $session->token_id)
            ->first();

        if ($token) {
            DB::table('oauth_access_tokens')
                ->where('id', $session->token_id)
                ->update(['revoked' => true]);
        }

        // Delete the user session record
        $session->delete();

        return response()->noContent();
    }
}
