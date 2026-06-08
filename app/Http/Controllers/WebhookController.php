<?php

namespace App\Http\Controllers;

use App\Enums\WebhookEvent;
use App\Models\Webhook;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function __construct(protected WebhookService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(
            Auth::user()->webhooks()->withCount('deliveries')->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'   => 'required|string|max:100',
            'url'    => 'required|url|max:500',
            'events' => 'required|array|min:1',
            'events.*' => 'string',
            'secret' => 'nullable|string|max:255',
        ]);

        // Validate event names against the enum
        $validEvents = array_column(WebhookEvent::cases(), 'value');
        $validated['events'] = array_values(array_intersect($validated['events'], $validEvents));

        if (empty($validated['events'])) {
            return response()->json(['message' => 'No valid event types specified.'], 422);
        }

        $webhook = $this->service->createWebhook(Auth::user(), $validated);
        return response()->json($webhook, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $webhook = Auth::user()->webhooks()->findOrFail($id);
        $validated = $request->validate([
            'name'      => 'sometimes|string|max:100',
            'url'       => 'sometimes|url|max:500',
            'events'    => 'sometimes|array',
            'events.*'  => 'string',
            'is_active' => 'sometimes|boolean',
        ]);
        $webhook->update($validated);
        return response()->json($webhook);
    }

    public function destroy(int $id): JsonResponse
    {
        Auth::user()->webhooks()->findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    public function test(int $id): JsonResponse
    {
        $webhook = Auth::user()->webhooks()->findOrFail($id);
        $this->service->testWebhook($webhook);
        return response()->json(['message' => 'Test delivery queued.']);
    }

    public function deliveries(int $id): JsonResponse
    {
        $webhook = Auth::user()->webhooks()->findOrFail($id);
        $deliveries = $webhook->deliveries()->orderBy('created_at', 'desc')->limit(50)->get();
        return response()->json($deliveries);
    }

    public function availableEvents(): JsonResponse
    {
        return response()->json(
            array_map(fn ($case) => ['value' => $case->value, 'label' => $case->name], WebhookEvent::cases())
        );
    }
}
