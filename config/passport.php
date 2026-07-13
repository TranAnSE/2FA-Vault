<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Passport Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the guards you wish to use for the
    | Laravel Passport "personal access" token authentication.
    |
    */

    'guard' => 'api-guard',

    'middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Encryption Keys
    |--------------------------------------------------------------------------
    |
    | Passport uses encryption keys while generating secure access tokens.
    | By default, keys are stored as local files but can be set via env vars
    | (useful for Docker secrets and read-only filesystems).
    |
    */

    'private_key' => env('PASSPORT_PRIVATE_KEY'),

    'public_key' => env('PASSPORT_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Passport Database Connection
    |--------------------------------------------------------------------------
    |
    | By default, Passport models use your application's default DB connection.
    |
    */

    'connection' => env('PASSPORT_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Client UUIDs
    |--------------------------------------------------------------------------
    |
    | Passport v13 defaults to UUID client IDs. We keep the fork's existing
    | integer client IDs for backward compatibility with the oauth_clients
    | schema and the personal_access_client fallback column reads.
    |
    */

    'client_uuids' => false,

    /*
    |--------------------------------------------------------------------------
    | Key File Permissions Validation
    |--------------------------------------------------------------------------
    |
    | Passport v13 validates key file permissions on Unix. Disabled for the
    | Windows dev environment; production (Linux Docker) generates keys with
    | correct permissions via passport:install.
    |
    */

    'validate_key_permissions' => false,

];
