<?php

namespace App\Api\v1\Controllers;

use App\Http\Requests\Api\v1\StoreSecureNoteRequest;
use App\Http\Requests\Api\v1\UpdateSecureNoteRequest;
use App\Api\v1\Resources\SecureNoteResource;
use App\Http\Controllers\Controller;
use App\Models\SecureNote;
use App\Services\PersonalActivityLogger;
use App\Services\SecureNoteService;
use App\Enums\PersonalAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SecureNoteController extends Controller
{
    /**
     * @var SecureNoteService
     */
    protected SecureNoteService $noteService;

    /**
     * @var PersonalActivityLogger
     */
    protected PersonalActivityLogger $activityLogger;

    /**
     * Constructor.
     */
    public function __construct(SecureNoteService $noteService, PersonalActivityLogger $activityLogger)
    {
        $this->noteService = $noteService;
        $this->activityLogger = $activityLogger;
    }

    /**
     * Display a listing of the user's secure notes.
     *
     * @param  Request  $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        $notes = $this->noteService->list($request->user());

        return SecureNoteResource::collection($notes);
    }

    /**
     * Store a newly created secure note in storage.
     *
     * @param  StoreSecureNoteRequest  $request
     * @return SecureNoteResource
     */
    public function store(StoreSecureNoteRequest $request)
    {
        $note = $this->noteService->create($request->user(), $request->validated());

        $this->activityLogger->log($request->user(), PersonalAction::NOTE_CREATED, [], $note->id);

        return new SecureNoteResource($note);
    }

    /**
     * Display the specified secure note.
     *
     * @param  Request  $request
     * @param  SecureNote  $secureNote
     * @return SecureNoteResource
     */
    public function show(Request $request, SecureNote $secureNote)
    {
        $this->authorize('view', $secureNote);

        return new SecureNoteResource($secureNote);
    }

    /**
     * Update the specified secure note in storage.
     *
     * @param  UpdateSecureNoteRequest  $request
     * @param  SecureNote  $secureNote
     * @return SecureNoteResource
     */
    public function update(UpdateSecureNoteRequest $request, SecureNote $secureNote)
    {
        $this->authorize('update', $secureNote);

        $note = $this->noteService->update($secureNote, $request->validated());

        $this->activityLogger->log($request->user(), PersonalAction::NOTE_UPDATED, [], $note->id);

        return new SecureNoteResource($note);
    }

    /**
     * Remove the specified secure note from storage.
     *
     * @param  Request  $request
     * @param  SecureNote  $secureNote
     * @return JsonResponse
     */
    public function destroy(Request $request, SecureNote $secureNote)
    {
        $this->authorize('delete', $secureNote);

        $noteId = $secureNote->id;

        $this->noteService->delete($secureNote);

        $this->activityLogger->log($request->user(), PersonalAction::NOTE_DELETED, [], $noteId);

        return response()->json([
            'message' => 'Secure note deleted successfully',
        ]);
    }
}
