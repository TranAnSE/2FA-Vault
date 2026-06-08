<?php

namespace App\Api\v1\Controllers;

use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class TagController extends Controller
{
    public function index(): JsonResponse
    {
        $tags = Auth::user()->tags()->withCount('accounts')->orderBy('name')->get();

        return response()->json($tags);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:100',
            'color' => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        $tag = Auth::user()->tags()->create([
            'name'  => $validated['name'],
            'color' => $validated['color'] ?? '#3273dc',
        ]);

        return response()->json($tag, 201);
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        if ($tag->user_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name'  => 'sometimes|string|max:100',
            'color' => 'sometimes|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        $tag->update($validated);

        return response()->json($tag);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        if ($tag->user_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $tag->delete();

        return response()->json(null, 204);
    }

    /**
     * Sync tags for a given 2FA account (replaces all existing tags).
     */
    public function syncAccountTags(Request $request, $accountId): JsonResponse
    {
        $account = Auth::user()->twofaccounts()->findOrFail($accountId);

        $validated = $request->validate([
            'tags'   => 'nullable|array',
            'tags.*' => 'integer|exists:tags,id',
        ]);

        $tagIds = $validated['tags'] ?? [];

        // Verify all tags belong to this user
        $userTagIds = Auth::user()->tags()->whereIn('id', $tagIds)->pluck('id')->toArray();
        $account->tags()->sync($userTagIds);

        return response()->json($account->load('tags'));
    }
}
