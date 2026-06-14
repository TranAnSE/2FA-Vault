<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * MetricsEndpointTest test class
 */
#[CoversClass(\App\Http\Middleware\MetricsAuthMiddleware::class)]
class MetricsEndpointTest extends FeatureTestCase
{
    use RefreshDatabase;

    protected function setUp() : void
    {
        parent::setUp();

        // The metrics controller caches the rendered output for 60s; start clean.
        Cache::forget('prometheus_metrics');
    }

    #[Test]
    public function test_allowed_ip_can_access_metrics()
    {
        config(['metrics.allowed_ips' => '127.0.0.1']);

        $response = $this->get('/metrics');

        $response->assertStatus(200);
        $this->assertStringStartsWith(
            'text/plain; version=0.0.4',
            $response->headers->get('Content-Type')
        );
        $response->assertSee('twofavault_users_total');
    }

    #[Test]
    public function test_bearer_token_can_access_metrics()
    {
        // No IP allowlist configured → only the token path can grant access.
        config(['metrics.allowed_ips' => '', 'metrics.token' => 'secret-metrics-token']);

        $response = $this->get('/metrics', ['Authorization' => 'Bearer secret-metrics-token']);

        $response->assertStatus(200);
        $response->assertSee('twofavault_accounts_total');
    }

    #[Test]
    public function test_request_without_auth_is_forbidden()
    {
        // No allowlist and no token configured → everything is rejected.
        config(['metrics.allowed_ips' => '', 'metrics.token' => '']);

        $this->get('/metrics')->assertForbidden();
    }

    #[Test]
    public function test_response_is_prometheus_text_format()
    {
        config(['metrics.allowed_ips' => '127.0.0.1']);

        $body = $this->get('/metrics')->getContent();

        $this->assertStringContainsString('# HELP twofavault_users_total', $body);
        $this->assertStringContainsString('# TYPE twofavault_users_total gauge', $body);
        $this->assertStringContainsString('# TYPE twofavault_rate_limit_hits_total counter', $body);
    }
}
