<?php

namespace App\Http\Controllers;

use App\Models\EmergencyAccessRequest;
use App\Models\EmergencyContact;
use App\Services\EmergencyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmergencyAccessController extends Controller
{
    public function __construct(protected EmergencyAccessService $service) {}

    /** List own emergency contacts */
    public function index(): JsonResponse
    {
        $contacts = EmergencyContact::where('owner_id', Auth::id())
            ->with('trustedUser:id,name,email')
            ->withCount(['accessRequests as pending_requests' => fn ($q) => $q->where('status', 'pending')])
            ->get();

        return response()->json($contacts);
    }

    /** Designate a new trusted contact */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'       => 'required|email',
            'wait_days'   => 'required|integer|in:7,14,30,60,90',
            'access_type' => 'required|in:view_only,full_access',
        ]);

        try {
            $contact = $this->service->designateContact(
                Auth::user(),
                $validated['email'],
                $validated['wait_days'],
                $validated['access_type']
            );
            return response()->json($contact->load('trustedUser:id,name,email'), 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Revoke a contact */
    public function destroy(int $id): JsonResponse
    {
        $contact = EmergencyContact::where('owner_id', Auth::id())->findOrFail($id);
        $this->service->revokeContact($contact);
        return response()->json(null, 204);
    }

    /** Trusted contact requests access */
    public function requestAccess(int $contactId): JsonResponse
    {
        $contact = EmergencyContact::where('trusted_user_id', Auth::id())->findOrFail($contactId);

        try {
            $request = $this->service->requestAccess($contact);
            return response()->json($request, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Owner approves a request */
    public function approve(Request $request, int $requestId): JsonResponse
    {
        $validated = $request->validate(['encrypted_key' => 'nullable|string']);
        $accessRequest = EmergencyAccessRequest::whereHas('contact', fn ($q) => $q->where('owner_id', Auth::id()))->findOrFail($requestId);

        $this->service->approveRequest($accessRequest, $validated['encrypted_key'] ?? null);
        return response()->json(['message' => 'Access approved']);
    }

    /** Owner denies a request */
    public function deny(int $requestId): JsonResponse
    {
        $accessRequest = EmergencyAccessRequest::whereHas('contact', fn ($q) => $q->where('owner_id', Auth::id()))->findOrFail($requestId);
        $this->service->denyRequest($accessRequest);
        return response()->json(['message' => 'Access denied']);
    }

    /** List pending access requests for owner to review */
    public function pendingRequests(): JsonResponse
    {
        $requests = EmergencyAccessRequest::whereHas('contact', fn ($q) => $q->where('owner_id', Auth::id()))
            ->where('status', 'pending')
            ->with(['contact:id,email,access_type,wait_days', 'requester:id,name,email'])
            ->get();

        return response()->json($requests);
    }

    /** List contacts where the authenticated user IS the trusted contact */
    public function contactsForMe(): JsonResponse
    {
        $contacts = EmergencyContact::where('trusted_user_id', Auth::id())
            ->whereIn('status', ['confirmed', 'active'])
            ->with('owner:id,name,email')
            ->get();

        return response()->json($contacts);
    }
}
