<?php

namespace Tests\Unit\Services;

use App\Services\BreachMonitoringService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BreachMonitoringService behavior using Http::fake + cache assertions.
 */
class BreachMonitoringServiceTest extends TestCase
{
    private BreachMonitoringService $service;

    protected function setUp() : void
    {
        parent::setUp();
        config(['services.hibp.key' => 'test-key']);
        config(['services.hibp.base_url' => 'https://haveibeenpwned.com/api/v3']);
        Cache::flush();
        $this->service = new BreachMonitoringService;
    }

    #[Test]
    public function test_check_email_not_breached_on_404() : void
    {
        Http::fake([
            'haveibeenpwned.com/*' => Http::response([], 404),
        ]);

        $result = $this->service->checkEmail('safe@example.com');

        $this->assertFalse($result['breached']);
        $this->assertSame('hibp', $result['source']);
    }

    #[Test]
    public function test_check_email_breached_returns_count() : void
    {
        Http::fake([
            'haveibeenpwned.com/*' => Http::response([
                ['Name' => 'Adobe'],
                ['Name' => 'LinkedIn'],
            ], 200),
        ]);

        $result = $this->service->checkEmail('pwned@example.com');

        $this->assertTrue($result['breached']);
        $this->assertSame(2, $result['count']);
    }

    #[Test]
    public function test_check_email_without_key_returns_unknown() : void
    {
        config(['services.hibp.key' => null]);

        $result = (new BreachMonitoringService)->checkEmail('anyone@example.com');

        $this->assertSame('unknown', $result['source']);
        $this->assertFalse($result['breached']);
    }

    #[Test]
    public function test_check_email_cached_no_second_call() : void
    {
        Http::fake([
            'haveibeenpwned.com/*' => Http::response([], 404),
        ]);

        $this->service->checkEmail('cached@example.com');
        $this->service->checkEmail('cached@example.com');

        // Only one HTTP request despite two calls (cache hit)
        Http::assertSentCount(1);
    }

    #[Test]
    public function test_check_service_matches_by_domain() : void
    {
        Http::fake([
            'haveibeenpwned.com/api/v3/breaches' => Http::response([
                ['Name' => 'Adobe', 'Title' => 'Adobe', 'Domain' => 'adobe.com', 'PwnCount' => 150000000, 'BreachDate' => '2013-10-04'],
                ['Name' => 'Other', 'Title' => 'Other', 'Domain' => 'other.com'],
            ], 200),
        ]);

        $result = $this->service->checkService('adobe.com');

        $this->assertTrue($result['breached']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('adobe.com', $result['breaches'][0]['domain']);
    }

    #[Test]
    public function test_check_service_hibp_outage_degrades_to_unknown() : void
    {
        Http::fake([
            'haveibeenpwned.com/*' => Http::response('Server Error', 500),
        ]);

        $result = $this->service->checkService('github.com');

        $this->assertSame('unknown', $result['source']);
    }
}
