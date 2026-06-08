<?php

namespace App\Services;

use App\Enums\WebhookEvent;
use App\Jobs\WebhookDeliveryJob;
use App\Models\User;
use App\Models\Webhook;

class WebhookService
{
    /**
     * Dispatch webhook deliveries to all subscribers for the given event.
     * Non-blocking — queued via Laravel jobs.
     */
    public function dispatch(WebhookEvent $event, array $payload, ?User $user = null): void
    {
        $query = Webhook::where('is_active', true)
            ->whereJsonContains('events', $event->value);

        if ($user) {
            $query->where('user_id', $user->id);
        }

        $query->each(function (Webhook $webhook) use ($event, $payload) {
            WebhookDeliveryJob::dispatch($webhook, $event->value, $payload);
        });
    }

    public function createWebhook(User $user, array $data): Webhook
    {
        return Webhook::create([
            'user_id' => $user->id,
            'name'    => $data['name'],
            'url'     => $data['url'],
            'secret'  => $data['secret'] ?? null,
            'events'  => $data['events'],
        ]);
    }

    public function testWebhook(Webhook $webhook): bool
    {
        $payload = ['event' => 'webhook.test', 'timestamp' => now()->toISOString()];
        WebhookDeliveryJob::dispatch($webhook, 'webhook.test', $payload);
        return true;
    }
}
