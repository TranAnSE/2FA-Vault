<?php

/**
 * Sentry configuration for 2FA-Vault.
 *
 * Sentry is OPTIONAL and DISABLED by default. Leave SENTRY_DSN empty and the
 * SDK stays inert: no network calls, no overhead, the 'sentry' container
 * binding is not registered, and the reportable hook in
 * app/Exceptions/Handler.php is a no-op.
 *
 * Works with both Sentry SaaS (sentry.io) and self-hosted Sentry
 * (https://develop.sentry.dev/self-hosted/). Only the DSN differs.
 *
 * Privacy: send_default_pii is forced to false. 2FA-Vault is a zero-knowledge
 * OTP vault; master passwords and account secrets never reach the server and
 * must never be attached to Sentry events. Do not enable send_default_pii.
 */

return [
    // DSN from your Sentry project settings. Empty = Sentry disabled.
    'dsn' => env('SENTRY_DSN'),

    // Release identifier (e.g. git commit SHA). Falls back to the COMMIT env
    // var set by the Docker image build args.
    'release' => env('SENTRY_RELEASE', env('COMMIT')),

    // Environment tag (production, staging, etc.). Falls back to APP_ENV.
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV')),

    // Fraction of errors to send. 1.0 = all, 0.0 = none.
    'sample_rate' => (float) env('SENTRY_SAMPLE_RATE', 1.0),

    // Performance tracing sample rate. 0.0 = tracing disabled (default).
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),

    // PII (personally identifiable information) MUST stay disabled for an
    // OTP vault. Changing this to true would attach request IPs, cookies, and
    // user context to Sentry events.
    'send_default_pii' => false,

    // Capture Laravel default logger (warning and above) as Sentry breadcrumbs.
    'breadcrumbs' => [
        // Logs emitted via Log::warning()/error() become breadcrumbs.
        'logs' => true,
        // SQL queries become breadcrumbs.
        'queries' => true,
        // Laravel queue jobs become breadcrumbs.
        'queue_info' => true,
        // HTTP client requests become breadcrumbs.
        'http_client_requests' => true,
        // Cache reads/writes become breadcrumbs.
        'cache' => true,
    ],
];
