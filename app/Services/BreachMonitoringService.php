<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Checks user emails and service names against HaveIBeenPwned (HIBP v3).
 *
 * Two check types:
 *  - checkEmail(): authenticated GET /breachedaccount/{account}. Sends the
 *    user's email to HIBP, so it MUST be gated by the `breachMonitoring`
 *    user preference (enforced in the controller).
 *  - checkService(): public GET /breaches filtered by domain. Sends only a
 *    public service/domain name; no opt-in required.
 *
 * Results are cached 24h per email-hash / per service to respect HIBP ToS and
 * free-tier limits. 429 responses use Laravel's retry/backoff. On HIBP outage
 * the service degrades to an "unknown" result instead of throwing.
 */
class BreachMonitoringService
{
    private const CACHE_TTL_SECONDS = 24 * 60 * 60;

    private const HTTP_TIMEOUT = 15;

    /**
     * Only retry transient failures (rate-limiting and server errors) plus
     * connection exceptions — never definitive client errors like 404/422.
     *
     * Laravel passes `$response->toException()` to the when-callback: a
     * RequestException for 4xx/5xx responses, null on success, or a
     * ConnectionException on transport failure.
     */
    private function retryWhen() : \Closure
    {
        return function ($exception) {
            if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                $status = $exception->response->status();

                return $status === 429 || $status >= 500;
            }

            // null = success (no retry); throwable = transport error (retry)
            return $exception instanceof \Throwable;
        };
    }

    public function __construct() {}

    /**
     * Check whether an email appears in any HIBP breach.
     * Caller must enforce the breachMonitoring opt-in preference.
     *
     * @return array{breached: bool, count: int, breaches: array, source: string}
     */
    public function checkEmail(string $email) : array
    {
        $key = 'hibp:email:' . hash('sha256', strtolower($email));

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($email) {
            $key = config('services.hibp.key');

            if (! $key) {
                Log::warning('HIBP email check requested but no API key is configured');

                return $this->unknownResult();
            }

            try {
                $response = Http::withHeaders([
                    'hibp-api-key' => $key,
                    'Accept'       => 'application/json',
                    'User-Agent'   => '2FA-Vault',
                ])
                    ->timeout(self::HTTP_TIMEOUT)
                    ->retry(times: 3, sleepMilliseconds: 1000, when: $this->retryWhen(), throw: false)
                    ->get($this->url('/breachedaccount/' . urlencode($email)) . '?truncateResponse=true');
            } catch (\Throwable $e) {
                Log::warning('HIBP email request failed', ['error' => $e->getMessage()]);

                return $this->unknownResult();
            }

            // 404 = not breached (HIBP convention)
            if ($response->status() === 404) {
                return ['breached' => false, 'count' => 0, 'breaches' => [], 'source' => 'hibp'];
            }

            if (! $response->successful()) {
                return $this->unknownResult();
            }

            $breaches = $response->json() ?: [];

            return [
                'breached' => count($breaches) > 0,
                'count'    => count($breaches),
                'breaches' => $breaches,
                'source'   => 'hibp',
            ];
        });
    }

    /**
     * Check whether a service/domain appears in the public HIBP breaches list.
     * No API key required; no opt-in required (public data only).
     *
     * @return array{breached: bool, count: int, breaches: array, source: string}
     */
    public function checkService(string $serviceName) : array
    {
        $normalized = strtolower(trim($serviceName));

        return Cache::remember('hibp:service:' . hash('sha256', $normalized), self::CACHE_TTL_SECONDS, function () use ($normalized) {
            try {
                $response = Http::withHeaders(['User-Agent' => '2FA-Vault'])
                    ->timeout(self::HTTP_TIMEOUT)
                    ->retry(times: 3, sleepMilliseconds: 1000, when: $this->retryWhen(), throw: false)
                    ->get($this->url('/breaches'));
            } catch (\Throwable $e) {
                Log::warning('HIBP service request failed', ['error' => $e->getMessage()]);

                return $this->unknownResult();
            }

            if (! $response->successful()) {
                return $this->unknownResult();
            }

            $all = $response->json() ?: [];

            // Match against breach Domain or Title (case-insensitive contains)
            $matches = array_values(array_filter($all, function ($breach) use ($normalized) {
                $domain = strtolower($breach['Domain'] ?? '');
                $title  = strtolower($breach['Name'] ?? '');

                return $domain === $normalized
                    || $title === $normalized
                    || ($domain !== '' && str_contains($normalized, $domain));
            }));

            return [
                'breached' => count($matches) > 0,
                'count'    => count($matches),
                'breaches' => array_map(fn ($b) => [
                    'name'        => $b['Name'] ?? null,
                    'title'       => $b['Title'] ?? null,
                    'domain'      => $b['Domain'] ?? null,
                    'pwn_count'   => $b['PwnCount'] ?? null,
                    'breach_date' => $b['BreachDate'] ?? null,
                ], $matches),
                'source' => 'hibp',
            ];
        });
    }

    private function url(string $path) : string
    {
        return rtrim(config('services.hibp.base_url'), '/') . $path;
    }

    private function unknownResult() : array
    {
        return ['breached' => false, 'count' => 0, 'breaches' => [], 'source' => 'unknown'];
    }
}
