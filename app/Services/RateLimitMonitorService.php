<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RateLimitMonitorService
{
    /**
     * Log a rate-limited request asynchronously (fire-and-forget after response).
     */
    public static function logLimitedRequest(
        ?User $user,
        string $ip,
        string $endpoint,
        string $method,
        ?string $userAgent = null
    ): void {
        dispatch(static function () use ($user, $ip, $endpoint, $method, $userAgent) {
            DB::table('rate_limit_logs')->insert([
                'user_id'    => $user?->id,
                'ip_address' => $ip,
                'endpoint'   => $endpoint,
                'method'     => $method,
                'was_limited'=> true,
                'user_agent' => substr($userAgent ?? '', 0, 500),
                'created_at' => now(),
            ]);
        })->afterResponse();
    }

    public function getDashboardData(string $period = '24h'): array
    {
        $from = match ($period) {
            '7d'  => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subHours(24),
        };

        $base = DB::table('rate_limit_logs')->where('created_at', '>=', $from);

        $total   = (clone $base)->count();
        $limited = (clone $base)->where('was_limited', true)->count();

        $topConsumers = (clone $base)
            ->where('was_limited', true)
            ->selectRaw('ip_address, user_id, COUNT(*) as hit_count')
            ->groupBy('ip_address', 'user_id')
            ->orderByDesc('hit_count')
            ->limit(10)
            ->get();

        $topEndpoints = (clone $base)
            ->where('was_limited', true)
            ->selectRaw('endpoint, COUNT(*) as hit_count')
            ->groupBy('endpoint')
            ->orderByDesc('hit_count')
            ->limit(10)
            ->get();

        $hourExpression = match (DB::getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d %H:00:00', created_at)",
            'mysql', 'mariadb' => "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')",
            default => "date_trunc('hour', created_at)::text",
        };

        $hourly = (clone $base)
            ->where('was_limited', true)
            ->selectRaw("{$hourExpression} as hour, COUNT(*) as count")
            ->groupByRaw($hourExpression)
            ->orderBy('hour')
            ->get();

        return compact('total', 'limited', 'topConsumers', 'topEndpoints', 'hourly');
    }

    public function pruneOldLogs(int $days = 30): int
    {
        return DB::table('rate_limit_logs')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
