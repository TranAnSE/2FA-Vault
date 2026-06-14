<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SecureNote;
use App\Models\Team;
use App\Models\TwoFAccount;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MetricsController extends Controller
{
    /**
     * Expose Prometheus-format metrics.
     * All DB queries are cached for 60s to avoid load on every scrape.
     *
     * @return Response
     */
    public function index()
    {
        $metrics = Cache::remember('prometheus_metrics', 60, function () {
            $lines = [];

            $this->addGauge($lines, 'twofavault_users_total', 'Total number of registered users', 'gauge', User::count());
            $this->addGauge($lines, 'twofavault_accounts_total', 'Total number of 2FA accounts', 'gauge', TwoFAccount::count());
            $this->addGauge($lines, 'twofavault_teams_total', 'Total number of teams', 'gauge', Team::count());
            $this->addGauge($lines, 'twofavault_secure_notes_total', 'Total number of secure notes', 'gauge', SecureNote::count());

            // Webhook deliveries (counter metric, split by status label)
            $successful = WebhookDelivery::where('status', 'success')->count();
            $failed = WebhookDelivery::where('status', 'failed')->count();
            $lines[] = '# HELP twofavault_webhook_deliveries_total Total webhook deliveries by status';
            $lines[] = '# TYPE twofavault_webhook_deliveries_total counter';
            $lines[] = sprintf('twofavault_webhook_deliveries_total{status="success"} %d', $successful);
            $lines[] = sprintf('twofavault_webhook_deliveries_total{status="failed"} %d', $failed);
            $lines[] = '';

            // Last backup timestamp (epoch seconds)
            $lastBackup = DB::table('user_backup_destinations')
                ->whereNotNull('last_run_at')
                ->max('last_run_at');
            $this->addGauge($lines, 'twofavault_last_backup_timestamp_seconds', 'Epoch seconds of the most recent backup run', 'gauge', $lastBackup ? strtotime($lastBackup) : 0);

            // Rate limit hits — guard against missing table
            try {
                $rateLimitHits = DB::table('rate_limit_logs')->count();
            } catch (\Throwable) {
                $rateLimitHits = 0;
            }
            $this->addGauge($lines, 'twofavault_rate_limit_hits_total', 'Total rate-limited requests', 'counter', $rateLimitHits);

            return implode("\n", $lines);
        });

        return response($metrics, 200)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }

    /**
     * Append a HELP/TYPE/value triple for a single-valued metric.
     *
     * @param  array<int,string>  $lines
     */
    private function addGauge(array &$lines, string $name, string $help, string $type, int $value): void
    {
        $lines[] = '# HELP ' . $name . ' ' . $help;
        $lines[] = '# TYPE ' . $name . ' ' . $type;
        $lines[] = $name . ' ' . $value;
        $lines[] = '';
    }
}
