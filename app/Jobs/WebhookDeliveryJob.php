<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Traits\ValidatesUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use ValidatesUrls;

    public int $tries = 3;

    public int $backoff = 60; // seconds between retries

    public function __construct(
        protected Webhook $webhook,
        protected string $event,
        protected array $payload
    ) {}

    public function handle() : void
    {
        $body = json_encode([
            'event'     => $this->event,
            'timestamp' => now()->toISOString(),
            'data'      => $this->payload,
        ]);

        $headers = ['Content-Type' => 'application/json', 'X-2FA-Vault-Event' => $this->event];

        if ($this->webhook->secret) {
            $headers['X-2FA-Vault-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $this->webhook->secret);
        }

        $delivery = WebhookDelivery::create([
            'webhook_id' => $this->webhook->id,
            'event'      => $this->event,
            'payload'    => $this->payload,
            'attempt'    => $this->attempts(),
        ]);

        try {
            // Defense in depth: re-validate the destination at dispatch time so a
            // webhook URL that has since started resolving to an internal range
            // (DNS rebinding) cannot be abused for SSRF from the queue worker.
            if (! $this->isPublicRemoteUrl($this->webhook->url)) {
                throw new \RuntimeException('Webhook URL does not resolve to a public address (SSRF guard)');
            }

            $response = Http::timeout(10)->withHeaders($headers)->post($this->webhook->url, json_decode($body, true));

            $delivery->update([
                'status_code'   => $response->status(),
                'success'       => $response->successful(),
                'response_body' => substr($response->body(), 0, 1000),
                'delivered_at'  => now(),
            ]);

            $this->webhook->update(['last_triggered_at' => now()]);

            if (! $response->successful()) {
                $this->fail(new \RuntimeException("Webhook delivery failed with status {$response->status()}"));
            }
        } catch (\Throwable $e) {
            $delivery->update(['success' => false, 'response_body' => $e->getMessage()]);
            Log::warning('Webhook delivery failed', ['webhook' => $this->webhook->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
