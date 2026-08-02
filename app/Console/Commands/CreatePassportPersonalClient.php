<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;

/**
 * Creates the Passport personal access client idempotently.
 *
 * Passport v13's built-in passport:client --personal command uses an
 * interactive choice() prompt that breaks under --no-interaction (CI) and
 * Mockery output mocking (tests). This command creates the client directly
 * via the ClientRepository, skipping the prompt.
 */
class CreatePassportPersonalClient extends Command
{
    protected $signature = 'passport:create-personal-client
                            {--name= : The client name}
                            {--provider=users : The user provider name}';

    protected $description = 'Create a Passport personal access client (non-interactive, idempotent)';

    public function handle(ClientRepository $clients) : void
    {
        $name     = $this->option('name') ?: config('app.name') . ' Personal Access Client';
        $provider = $this->option('provider');

        // Check if a personal access client already exists.
        $existing = \DB::table('oauth_clients')->where('personal_access_client', 1)->first();

        if ($existing) {
            $this->components->info('Personal access client already exists (id: ' . $existing->id . ').');

            return;
        }

        $client = $clients->createPersonalAccessGrantClient($name, $provider);

        $this->components->info('Personal access client created successfully.');
        $this->components->twoColumnDetail('Client ID', $client->getKey());
    }
}
