<?php

namespace App\Api\v1\Controllers;

use App\Api\v1\Resources\UserInvitationResource;
use App\Enums\PersonalAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\v1\StoreInvitationRequest;
use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\PersonalActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    /**
     * @var PersonalActivityLogger
     */
    protected PersonalActivityLogger $activityLogger;

    /**
     * Constructor.
     */
    public function __construct(PersonalActivityLogger $activityLogger)
    {
        $this->activityLogger = $activityLogger;
    }

    /**
     * Store a newly created invitation in storage.
     *
     * @param  StoreInvitationRequest  $request
     * @return UserInvitationResource
     */
    public function store(StoreInvitationRequest $request)
    {
        $validated = $request->validated();

        // Check if user already registered
        if (User::where('email', $validated['email'])->exists()) {
            return response()->json([
                'message' => 'A user with this email already exists',
            ], 422);
        }

        $expiresAt = isset($validated['expires_at'])
            ? $validated['expires_at']
            : now()->addDays(7);

        // Create invitation
        $invitation = UserInvitation::create([
            'email'        => $validated['email'],
            'token'        => Str::random(64),
            'invited_by_id' => $request->user()->id,
            'role'         => $validated['role'] ?? 'user',
            'expires_at'   => $expiresAt,
        ]);

        // Send invitation email
        Mail::to($validated['email'])->send(new UserInvitationMail($invitation));

        $this->activityLogger->log($request->user(), PersonalAction::INVITATION_SENT, [
            'email'         => $validated['email'],
            'invitation_id' => $invitation->id,
        ]);

        return (new UserInvitationResource($invitation))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display a listing of pending invitations.
     *
     * @param  Request  $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        $invitations = UserInvitation::pending()->latest()->get();

        return UserInvitationResource::collection($invitations);
    }

    /**
     * Remove the specified invitation from storage (revocation).
     *
     * @param  Request  $request
     * @param  int  $id
     * @return JsonResponse
     */
    public function destroy(Request $request, $id)
    {
        $invitation = UserInvitation::findOrFail($id);

        $invitation->delete();

        return response()->json(null, 204);
    }
}
