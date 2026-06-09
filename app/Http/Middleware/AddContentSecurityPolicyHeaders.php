<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class AddContentSecurityPolicyHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next) : Response
    {
        if (!config('2fauth.config.contentSecurityPolicy')) {
            return $next($request);
        }

        // Minimal CSP for API routes — prevents framing and MIME sniffing
        if ($request->is('api/*')) {
            return $next($request)->withHeaders([
                'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        // Full CSP for web routes
        Vite::useCspNonce();
        $authorizedAddresses[] = $this->cspHostSource(config('app.url'));
        $authorizedAddresses[] = 'https://fastly.jsdelivr.net:*';

        if (config('app.asset_url') && config('app.asset_url') != config('app.url')) {
            $authorizedAddresses[] = $this->cspHostSource(config('app.asset_url'));
        }

        if (config('app.env') === 'development' && Vite::isRunningHot()) {
            $authorizedAddresses[] = 'ws://' . $request->getHttpHost() . ':*';
            $authorizedAddresses[] = 'http://127.0.0.1:*';
            $authorizedAddresses[] = 'ws://127.0.0.1:*';
        }

        $authorizedAddresses = implode(' ', $authorizedAddresses);

        $directives['script-src']  = "script-src 'nonce-" . Vite::cspNonce() . "' 'wasm-unsafe-eval' 'strict-dynamic'";
        $directives['style-src']   = "style-src 'self' " . $authorizedAddresses . " 'unsafe-inline'";
        $directives['connect-src'] = "connect-src 'self' " . $authorizedAddresses;
        $directives['img-src']     = "img-src 'self' data: " . $authorizedAddresses;
        $directives['object-src']  = "object-src 'none'";
        $directives['default-src'] = "default-src 'self'";
        $directives['base-uri']       = "base-uri 'self'";
        $directives['form-action']    = "form-action 'self'";
        $directives['frame-ancestors'] = "frame-ancestors 'none'";

        if (config('app.env') === 'development') {
            $directives['script-src'] .= " 'unsafe-eval'";
        }

        $csp = implode('; ', $directives);

        /** @disregard Undefined function */
        /** @phpstan-ignore-next-line */
        return $next($request)->withHeaders([
            'Content-Security-Policy' => $csp,
        ]);
    }

    /**
     * Build a CSP host-source entry from a URL. Appends `:*` only when the
     * URL does not already carry an explicit port, so we never emit the
     * invalid `http://host:port:*` form that browsers discard.
     */
    private function cspHostSource(string $url) : string
    {
        return parse_url($url, PHP_URL_PORT) ? $url : $url . ':*';
    }
}
