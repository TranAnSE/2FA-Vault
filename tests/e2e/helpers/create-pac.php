<?php
// Creates the Passport personal access client for the e2e environment.
// Run via: php artisan tinker --env=e2e < tests/e2e/helpers/create-pac.php
use Laravel\Passport\ClientRepository;

$repo = app(ClientRepository::class);

// Check if a personal access client already exists to avoid duplicates.
$existing = DB::table('oauth_clients')->where('personal_access_client', 1)->first();
if (!$existing) {
    $repo->createPersonalAccessGrantClient('E2E Personal Access Client', 'users');
    echo "Created E2E Personal Access Client.\n";
} else {
    echo "E2E Personal Access Client already exists.\n";
}
