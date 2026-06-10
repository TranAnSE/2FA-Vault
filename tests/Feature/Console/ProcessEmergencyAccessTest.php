<?php

namespace Tests\Feature\Console;

use App\Models\EmergencyAccessRequest;
use App\Models\EmergencyContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessEmergencyAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_successfully(): void
    {
        $this->artisan('emergency:process')
            ->assertSuccessful();
    }

    public function test_command_processes_expired_requests(): void
    {
        $owner = User::factory()->create(['last_seen_at' => now()]);
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
            'wait_days' => 7,
            'status' => 'confirmed',
        ]);

        EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status' => 'pending',
            'requested_at' => now()->subDays(8),
        ]);

        $this->artisan('emergency:process')
            ->expectsOutput('Emergency access: 1 requests auto-granted, 0 dead man\'s switches triggered.')
            ->assertSuccessful();
    }

    public function test_command_reports_counts(): void
    {
        // Set 1: expired request for auto-granting
        $owner1 = User::factory()->create(['last_seen_at' => now()]);
        $contact1 = EmergencyContact::factory()->create([
            'owner_id' => $owner1->id,
            'wait_days' => 7,
            'status' => 'confirmed',
        ]);
        EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact1->id,
            'status' => 'pending',
            'requested_at' => now()->subDays(8),
        ]);

        // Set 2: inactive owner with confirmed contact, no existing request (dead man's switch)
        $inactiveOwner = User::factory()->create([
            'last_seen_at' => now()->subDays(35),
        ]);
        EmergencyContact::factory()->create([
            'owner_id' => $inactiveOwner->id,
            'wait_days' => 30,
            'status' => 'confirmed',
        ]);

        $this->artisan('emergency:process')
            ->expectsOutput('Emergency access: 1 requests auto-granted, 1 dead man\'s switches triggered.')
            ->assertSuccessful();
    }

    public function test_command_handles_no_expired_requests(): void
    {
        $owner = User::factory()->create(['last_seen_at' => now()]);
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
            'wait_days' => 30,
            'status' => 'confirmed',
        ]);

        // Request within wait period - should not be processed
        EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status' => 'pending',
            'requested_at' => now()->subDays(5),
        ]);

        $this->artisan('emergency:process')
            ->expectsOutput('Emergency access: 0 requests auto-granted, 0 dead man\'s switches triggered.')
            ->assertSuccessful();
    }
}
