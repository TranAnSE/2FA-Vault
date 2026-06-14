<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MetricsAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $allowedIps = collect(explode(',', config('metrics.allowed_ips', '')))
            ->filter()
            ->map(fn ($ip) => trim($ip));

        $metricsToken = config('metrics.token');

        // Check IP allowlist
        if ($allowedIps->isNotEmpty() && $allowedIps->contains($request->ip())) {
            return $next($request);
        }

        // Check Bearer token
        if ($metricsToken && $request->bearerToken() === $metricsToken) {
            return $next($request);
        }

        // Unauthorized
        abort(403, 'Unauthorized access to metrics endpoint');
    }
}
