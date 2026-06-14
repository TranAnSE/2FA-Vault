<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\MetricsController;
use App\Models\SecureNote;
use App\Models\Team;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MetricsControllerTest test class
 */
#[CoversClass(MetricsController::class)]
class MetricsControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_index_returns_prometheus_text_plain()
    {
        Cache::flush();

        $response = (new MetricsController())->index();

        $this->assertSame(200, $response->status());
        $this->assertStringStartsWith(
            'text/plain; version=0.0.4',
            $response->headers->get('Content-Type')
        );

        $body = $response->getContent();

        $this->assertStringContainsString('twofavault_users_total', $body);
        $this->assertStringContainsString('twofavault_accounts_total', $body);
        $this->assertStringContainsString('twofavault_teams_total', $body);
        $this->assertStringContainsString('twofavault_secure_notes_total', $body);
        $this->assertStringContainsString('twofavault_webhook_deliveries_total{status="success"}', $body);
        $this->assertStringContainsString('twofavault_webhook_deliveries_total{status="failed"}', $body);
        $this->assertStringContainsString('twofavault_last_backup_timestamp_seconds', $body);
        $this->assertStringContainsString('twofavault_rate_limit_hits_total', $body);
    }

    #[Test]
    public function test_index_reflects_current_counts()
    {
        Cache::flush();

        // Use a single user and attach children to it so factory cascade-creation
        // does not inflate the user count in unpredictable ways.
        $user = User::factory()->create();
        SecureNote::factory()->count(4)->forUser($user)->create();
        TwoFAccount::factory()->count(2)->forUser($user)->create();

        $response = (new MetricsController())->index();
        $body = $response->getContent();

        $this->assertStringContainsString('twofavault_secure_notes_total 4', $body);
        $this->assertStringContainsString('twofavault_accounts_total 2', $body);
        $this->assertStringContainsString('twofavault_users_total 1', $body);
        // No deliveries exist → both status lines report 0
        $this->assertStringContainsString('twofavault_webhook_deliveries_total{status="success"} 0', $body);
    }

    #[Test]
    public function test_index_uses_cache_on_second_call()
    {
        Cache::flush();

        $queriesBefore = DB::connection()->getQueryLog();
        DB::enableQueryLog();

        $controller = new MetricsController();
        $controller->index();

        $queriesAfterFirst = count(DB::getQueryLog());
        DB::flushQueryLog();

        $controller->index();
        $queriesAfterSecond = count(DB::getQueryLog());

        DB::disableQueryLog();

        // First call hits the DB; the cached second call must not.
        $this->assertGreaterThan(0, $queriesAfterFirst);
        $this->assertSame(0, $queriesAfterSecond);
    }
}
