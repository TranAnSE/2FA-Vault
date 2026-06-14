<?php

namespace App\Api\v1\Controllers;

use App\Api\v1\Resources\PersonalActivityLogResource;
use App\Http\Controllers\Controller;
use App\Models\PersonalActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PersonalActivityController extends Controller
{
    /**
     * Display a listing of the user's personal activity logs.
     *
     * @param  Request  $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $logs = $user->personalActivityLogs()
            ->latest()
            ->paginate(50);

        return PersonalActivityLogResource::collection($logs);
    }

    /**
     * Clear all personal activity logs for the authenticated user.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     */
    public function destroyAll(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $user->personalActivityLogs()->delete();

        return response()->noContent();
    }
}
