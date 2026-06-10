<?php

namespace Tests\Feature\Services;

use App\Models\EmergencyAccessRequest;
use App\Models\EmergencyContact;
use App\Models\User;
use App\Services\EmergencyAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmergencyAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyAccessService $service;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EmergencyAccessService();
        $this->owner = User::factory()->create();
    }

    public function test_can_designate_contact(): void
    {
        $trusted = User::factory()->create(['email' => 'trusted@example.com']);

        $contact = $this->service->designateContact(
            $this->owner,
            'trusted@example.com',
            30,
            'view_only'
        );

        $this->assertInstanceOf(EmergencyContact::class, $contact);
        $this->assertEquals($this->owner->id, $contact->owner_id);
        $this->assertEquals($trusted->id, $contact->trusted_user_id);
        $this->assertEquals('view_only', $contact->access_type);
        $this->assertEquals(30, $contact->wait_days);

        $this->assertDatabaseHas('emergency_contacts', [
            'owner_id' => $this->owner->id,
            'email' => 'trusted@example.com',
        ]);
    }

    public function test_cannot_designate_self(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot designate yourself');

        $this->service->designateContact(
            $this->owner,
            $this->owner->email,
            30,
            'view_only'
        );
    }

    public function test_cannot_exceed_max_contacts(): void
    {
        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('Maximum of 5');

        for ($i = 0; $i < 6; $i++) {
            $this->service->designateContact(
                $this->owner,
                "contact{$i}@example.com",
                30,
                'view_only'
            );
        }
    }

    public function test_designate_contact_sets_confirmed_if_user_exists(): void
    {
        $existing = User::factory()->create(['email' => 'existing@example.com']);

        $contact = $this->service->designateContact(
            $this->owner,
            'existing@example.com',
            14,
            'full_access'
        );

        $this->assertEquals('confirmed', $contact->status);
        $this->assertEquals($existing->id, $contact->trusted_user_id);
    }

    public function test_designate_contact_sets_pending_if_user_not_found(): void
    {
        $contact = $this->service->designateContact(
            $this->owner,
            'unknown@example.com',
            7,
            'view_only'
        );

        $this->assertEquals('pending', $contact->status);
        $this->assertNull($contact->trusted_user_id);
    }

    public function test_can_request_access(): void
    {
        $trusted = User::factory()->create();
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $this->owner->id,
            'trusted_user_id' => $trusted->id,
            'status' => 'confirmed',
        ]);

        $request = $this->service->requestAccess($contact);

        $this->assertInstanceOf(EmergencyAccessRequest::class, $request);
        $this->assertEquals($contact->id, $request->contact_id);
        $this->assertEquals($trusted->id, $request->requester_id);
        $this->assertEquals('pending', $request->status);
        $this->assertNotNull($request->requested_at);
    }

    public function test_cannot_request_for_non_active_contact(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not active');

        $contact = EmergencyContact::factory()->create([
            'owner_id' => $this->owner->id,
            'status' => 'pending',
        ]);

        $this->service->requestAccess($contact);
    }

    public function test_can_approve_request(): void
    {
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $this->owner->id,
            'status' => 'confirmed',
        ]);

        $request = EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status' => 'pending',
        ]);

        $this->service->approveRequest($request, 'encrypted-key-data');

        $request->refresh();
        $contact->refresh();

        $this->assertEquals('approved', $request->status);
        $this->assertNotNull($request->responded_at);
        $this->assertNotNull($request->granted_at);

        $this->assertEquals('active', $contact->status);
        $this->assertNotNull($contact->granted_at);
        $this->assertEquals('encrypted-key-data', $contact->encrypted_key);
    }

    public function test_can_deny_request(): void
    {
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $this->owner->id,
        ]);

        $request = EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status' => 'pending',
        ]);

        $this->service->denyRequest($request);

        $request->refresh();

        $this->assertEquals('denied', $request->status);
        $this->assertNotNull($request->responded_at);
    }

    public function test_can_revoke_contact(): void
    {
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $this->owner->id,
            'status' => 'active',
            'encrypted_key' => 'some-key',
            'granted_at' => now(),
        ]);

        EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status' => 'pending',
        ]);

        $this->service->revokeContact($contact);

        $contact->refresh();

        $this->assertEquals('revoked', $contact->status);
        $this->assertNull($contact->encrypted_key);
        $this->assertNull($contact->granted_at);

        // Pending requests should be denied
        $this->assertDatabaseHas('emergency_access_requests', [
            'contact_id' => $contact->id,
            'status' => 'denied',
        ]);
    }

    public function test_process_expired_requests_grants_expired(): void
    {
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $this->owner->id,
            'wait_days' => 7,
            'status' => 'confirmed',
        ]);

        // Request created 8 days ago (past the 7-day wait)
        EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status' => 'pending',
            'requested_at' => now()->subDays(8),
        ]);

        $count = $this->service->processExpiredRequests();

        $this->assertEquals(1, $count);

        $this->assertDatabaseHas('emergency_access_requests', [
            'contact_id' => $contact->id,
            'status' => 'auto_granted',
        ]);

        $contact->refresh();
        $this->assertEquals('active', $contact->status);
    }

    public function test_process_expired_requests_skips_within_wait_period(): void
    {
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $this->owner->id,
            'wait_days' => 30,
            'status' => 'confirmed',
        ]);

        // Request created 5 days ago (within the 30-day wait)
        EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status' => 'pending',
            'requested_at' => now()->subDays(5),
        ]);

        $count = $this->service->processExpiredRequests();

        $this->assertEquals(0, $count);

        // Should still be pending
        $this->assertDatabaseHas('emergency_access_requests', [
            'contact_id' => $contact->id,
            'status' => 'pending',
        ]);
    }

    public function test_check_dead_mans_switch_triggers_for_inactive_owner(): void
    {
        $inactiveOwner = User::factory()->create([
            'last_seen_at' => now()->subDays(35),
        ]);

        $contact = EmergencyContact::factory()->create([
            'owner_id' => $inactiveOwner->id,
            'wait_days' => 30,
            'status' => 'confirmed',
        ]);

        $count = $this->service->checkDeadMansSwitch();

        $this->assertEquals(1, $count);

        $contact->refresh();
        $this->assertEquals('active', $contact->status);

        $this->assertDatabaseHas('emergency_access_requests', [
            'contact_id' => $contact->id,
            'status' => 'auto_granted',
        ]);
    }
}
