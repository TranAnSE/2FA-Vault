<?php

namespace Tests\Feature\Services;

use App\Enums\WebhookEvent;
use App\Jobs\WebhookDeliveryJob;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookServiceTest extends TestCase
{
    use RefreshDatabase;

    private WebhookService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new WebhookService();
        $this->user = User::factory()->create();
    }

    public function test_can_create_webhook(): void
    {
        $webhook = $this->service->createWebhook($this->user, [
            'name'   => 'My Hook',
            'url'    => 'https://example.com/hook',
            'events' => ['account.created', 'account.updated'],
        ]);

        $this->assertInstanceOf(Webhook::class, $webhook);
        $this->assertTrue($webhook->exists);
    }

    public function test_create_webhook_stores_correct_data(): void
    {
        $webhook = $this->service->createWebhook($this->user, [
            'name'   => 'Test Hook',
            'url'    => 'https://example.com/hook',
            'secret' => 'my-secret',
            'events' => ['account.created', 'vault.locked'],
        ]);

        $this->assertEquals($this->user->id, $webhook->user_id);
        $this->assertEquals('Test Hook', $webhook->name);
        $this->assertEquals('https://example.com/hook', $webhook->url);
        $this->assertEquals('my-secret', $webhook->secret);
        $this->assertEquals(['account.created', 'vault.locked'], $webhook->events);
        $this->assertDatabaseHas('webhooks', ['id' => $webhook->id]);
    }

    public function test_can_toggle_webhook_active_state(): void
    {
        $webhook = $this->service->createWebhook($this->user, [
            'name'   => 'Toggle Hook',
            'url'    => 'https://example.com/hook',
            'events' => ['account.created'],
        ]);

        $this->assertTrue($webhook->fresh()->is_active);

        $webhook->update(['is_active' => false]);
        $this->assertFalse($webhook->fresh()->is_active);

        $webhook->update(['is_active' => true]);
        $this->assertTrue($webhook->fresh()->is_active);
    }

    public function test_can_delete_webhook(): void
    {
        $webhook = $this->service->createWebhook($this->user, [
            'name'   => 'Delete Hook',
            'url'    => 'https://example.com/hook',
            'events' => ['account.created'],
        ]);

        $webhook->delete();

        $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);
    }

    public function test_delete_webhook_removes_deliveries(): void
    {
        $webhook = $this->service->createWebhook($this->user, [
            'name'   => 'Deliveries Hook',
            'url'    => 'https://example.com/hook',
            'events' => ['account.created'],
        ]);

        WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event'      => 'account.created',
            'payload'    => ['test' => true],
            'attempt'    => 1,
        ]);

        $this->assertDatabaseHas('webhook_deliveries', ['webhook_id' => $webhook->id]);

        $webhook->delete();

        $this->assertDatabaseMissing('webhook_deliveries', ['webhook_id' => $webhook->id]);
    }

    public function test_dispatch_sends_to_matching_active_webhooks(): void
    {
        Queue::fake();

        $webhook = $this->service->createWebhook($this->user, [
            'name'   => 'Dispatch Hook',
            'url'    => 'https://example.com/hook',
            'events' => ['account.created'],
        ]);

        $this->service->dispatch(
            WebhookEvent::ACCOUNT_CREATED,
            ['account_id' => 1],
        );

        Queue::assertPushed(WebhookDeliveryJob::class, 1);
    }

    public function test_dispatch_skips_inactive_webhooks(): void
    {
        Queue::fake();

        $webhook = $this->service->createWebhook($this->user, [
            'name'   => 'Inactive Hook',
            'url'    => 'https://example.com/hook',
            'events' => ['account.created'],
        ]);
        $webhook->update(['is_active' => false]);

        $this->service->dispatch(
            WebhookEvent::ACCOUNT_CREATED,
            ['account_id' => 1],
        );

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function test_dispatch_filters_by_user(): void
    {
        Queue::fake();

        $otherUser = User::factory()->create();

        // Webhook owned by other user
        $this->service->createWebhook($otherUser, [
            'name'   => 'Other Hook',
            'url'    => 'https://example.com/other',
            'events' => ['account.created'],
        ]);

        // Dispatch scoped to $this->user — should not trigger otherUser's webhook
        $this->service->dispatch(
            WebhookEvent::ACCOUNT_CREATED,
            ['account_id' => 1],
            $this->user,
        );

        Queue::assertNotPushed(WebhookDeliveryJob::class);

        // Now add a webhook for $this->user and dispatch again
        $this->service->createWebhook($this->user, [
            'name'   => 'My Hook',
            'url'    => 'https://example.com/mine',
            'events' => ['account.created'],
        ]);

        $this->service->dispatch(
            WebhookEvent::ACCOUNT_CREATED,
            ['account_id' => 1],
            $this->user,
        );

        Queue::assertPushed(WebhookDeliveryJob::class, 1);
    }

    public function test_dispatch_skips_non_matching_events(): void
    {
        Queue::fake();

        $this->service->createWebhook($this->user, [
            'name'   => 'Vault Hook',
            'url'    => 'https://example.com/hook',
            'events' => ['vault.locked'],
        ]);

        // Dispatch a different event
        $this->service->dispatch(
            WebhookEvent::ACCOUNT_CREATED,
            ['account_id' => 1],
        );

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function test_test_webhook_dispatches_job(): void
    {
        Queue::fake();

        $webhook = $this->service->createWebhook($this->user, [
            'name'   => 'Test Dispatch',
            'url'    => 'https://example.com/hook',
            'events' => ['account.created'],
        ]);

        $result = $this->service->testWebhook($webhook);

        $this->assertTrue($result);
        Queue::assertPushed(WebhookDeliveryJob::class, 1);
    }
}
