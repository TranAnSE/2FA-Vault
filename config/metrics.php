<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Metrics Allowed IPs
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of IP addresses that are allowed to access the
    | /metrics endpoint without authentication. If empty, only token auth is used.
    |
    */

    'allowed_ips' => env('METRICS_ALLOWED_IPS', ''),

    /*
    |--------------------------------------------------------------------------
    | Metrics Token
    |--------------------------------------------------------------------------
    |
    | The bearer token required to access the /metrics endpoint. This token
    | should be treated as a secret and set in the environment.
    |
    */

    'token' => env('METRICS_TOKEN', ''),
];
