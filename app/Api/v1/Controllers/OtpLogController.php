<?php

namespace App\Api\v1\Controllers;

use App\Api\v1\Resources\OtpLogResource;
use App\Http\Controllers\Controller;
use App\Models\OtpLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class OtpLogController extends Controller
{
    /**
     * List OTP generation logs visible to the authenticated user.
     *
     * A regular user sees logs where they are the requester or the owner.
     * An admin may optionally scope to a specific user via ?user_id=.
     *
     * @return AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $query = OtpLog::query()->latest('generated_at');

        if ($user->is_admin && $request->has('user_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('requester_id', $request->input('user_id'))
                    ->orWhere('owner_id', $request->input('user_id'));
            });
        } elseif (! $user->is_admin) {
            $query->where(function ($q) use ($user) {
                $q->where('requester_id', $user->id)
                    ->orWhere('owner_id', $user->id);
            });
        }

        if ($request->has('twofaccount_id')) {
            $query->where('twofaccount_id', (int) $request->input('twofaccount_id'));
        }

        return OtpLogResource::collection($query->paginate(50));
    }

    /**
     * Clear all OTP generation logs visible to the authenticated user.
     *
     * @return Response
     */
    public function destroyAll(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->is_admin) {
            OtpLog::query()->delete();
        } else {
            OtpLog::where('requester_id', $user->id)
                ->orWhere('owner_id', $user->id)
                ->delete();
        }

        return response()->noContent();
    }
}
