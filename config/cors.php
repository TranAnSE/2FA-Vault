<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // Default to a restrictive policy: no origins, standard method set, minimal
    // headers. Operators must opt-in via CORS_ALLOWED_ORIGINS. The legacy '*'
    // fallback was removed so a missing env var can never widen the surface.
    'allowed_methods' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS')))),

    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_HEADERS', 'Content-Type,X-Requested-With,Authorization,Accept')))),

    'exposed_headers' => [],

    'max_age' => (int) env('CORS_MAX_AGE', 86400),

    'supports_credentials' => env('CORS_SUPPORTS_CREDENTIALS', false),

];
