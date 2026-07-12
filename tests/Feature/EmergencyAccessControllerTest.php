<?php

namespace Tests\Feature;

use App\Models\EmergencyAccessRequest;
use App\Models\EmergencyContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class EmergencyAccessControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function createEncryptedUser(array $attributes = []) : User
    {
        return User::factory()->create(array_merge([
            'encryption_enabled'    => true,
            'encryption_salt'       => 'test_salt',
            'encryption_test_value' => '{"ciphertext":"test","iv":"test","authTag":"test"}',
            'encryption_version'    => 1,
            'vault_locked'          => false,
        ], $attributes));
    }

    public function test_user_can_list_contacts() : void
    {
        $owner = $this->createEncryptedUser();

        EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
            'status'   => 'confirmed',
        ]);
        EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
            'status'   => 'active',
        ]);

        Passport::actingAs($owner, [], 'api-guard');
        $response = $this
            ->getJson('/api/v1/emergency-contacts');

        $response->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_user_can_designate_contact() : void
    {
        $owner   = $this->createEncryptedUser();
        $trusted = $this->createEncryptedUser(['email' => 'trusted@example.com']);

        Passport::actingAs($owner, [], 'api-guard');
        $response = $this
            ->postJson('/api/v1/emergency-contacts', [
                'email'       => 'trusted@example.com',
                'wait_days'   => 30,
                'access_type' => 'view_only',
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'email'       => 'trusted@example.com',
                'access_type' => 'view_only',
            ]);

        $this->assertDatabaseHas('emergency_contacts', [
            'owner_id' => $owner->id,
            'email'    => 'trusted@example.com',
            'status'   => 'confirmed',
        ]);
    }

    public function test_returns_422_on_6th_contact() : void
    {
        $owner = $this->createEncryptedUser();

        // Create 5 existing contacts
        for ($i = 0; $i < 5; $i++) {
            EmergencyContact::factory()->create([
                'owner_id' => $owner->id,
                'email'    => "contact{$i}@example.com",
                'status'   => 'confirmed',
            ]);
        }

        Passport::actingAs($owner, [], 'api-guard');
        $response = $this
            ->postJson('/api/v1/emergency-contacts', [
                'email'       => 'sixth@example.com',
                'wait_days'   => 30,
                'access_type' => 'view_only',
            ]);

        $response->assertStatus(422);
    }

    public function test_user_can_revoke_contact() : void
    {
        $owner   = $this->createEncryptedUser();
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
            'status'   => 'confirmed',
        ]);

        Passport::actingAs($owner, [], 'api-guard');
        $response = $this
            ->deleteJson("/api/v1/emergency-contacts/{$contact->id}");

        $response->assertStatus(204);

        $this->assertDatabaseHas('emergency_contacts', [
            'id'     => $contact->id,
            'status' => 'revoked',
        ]);
    }

    public function test_grantee_can_request_access() : void
    {
        $owner   = $this->createEncryptedUser();
        $grantee = $this->createEncryptedUser();

        $contact = EmergencyContact::factory()->create([
            'owner_id'        => $owner->id,
            'trusted_user_id' => $grantee->id,
            'status'          => 'confirmed',
        ]);

        Passport::actingAs($grantee, [], 'api-guard');
        $response = $this
            ->postJson("/api/v1/emergency-contacts/{$contact->id}/request");

        $response->assertStatus(201)
            ->assertJsonFragment([
                'contact_id' => $contact->id,
                'status'     => 'pending',
            ]);
    }

    public function test_owner_can_approve_request() : void
    {
        $owner   = $this->createEncryptedUser();
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
            'status'   => 'confirmed',
        ]);

        $request = EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status'     => 'pending',
        ]);

        Passport::actingAs($owner, [], 'api-guard');
        $response = $this
            ->postJson("/api/v1/emergency-requests/{$request->id}/approve", [
                'encrypted_key' => 'aes-256-gcm-key-data',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Access approved']);

        $this->assertDatabaseHas('emergency_access_requests', [
            'id'     => $request->id,
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('emergency_contacts', [
            'id'     => $contact->id,
            'status' => 'active',
        ]);
    }

    public function test_owner_can_deny_request() : void
    {
        $owner   = $this->createEncryptedUser();
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
        ]);

        $request = EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status'     => 'pending',
        ]);

        Passport::actingAs($owner, [], 'api-guard');
        $response = $this
            ->postJson("/api/v1/emergency-requests/{$request->id}/deny");

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Access denied']);

        $this->assertDatabaseHas('emergency_access_requests', [
            'id'     => $request->id,
            'status' => 'denied',
        ]);
    }

    public function test_owner_can_list_pending_requests() : void
    {
        $owner   = $this->createEncryptedUser();
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
        ]);

        EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status'     => 'pending',
        ]);
        EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status'     => 'approved',
        ]);

        Passport::actingAs($owner, [], 'api-guard');
        $response = $this
            ->getJson('/api/v1/emergency-requests/pending');

        $response->assertStatus(200)
            ->assertJsonCount(1);
    }

    public function test_user_can_list_contacts_where_they_are_trusted() : void
    {
        $owner   = $this->createEncryptedUser();
        $grantee = $this->createEncryptedUser();

        EmergencyContact::factory()->create([
            'owner_id'        => $owner->id,
            'trusted_user_id' => $grantee->id,
            'status'          => 'confirmed',
        ]);
        EmergencyContact::factory()->create([
            'owner_id'        => $owner->id,
            'trusted_user_id' => $grantee->id,
            'status'          => 'active',
        ]);
        // Revoked contact should not appear
        EmergencyContact::factory()->create([
            'owner_id'        => $owner->id,
            'trusted_user_id' => $grantee->id,
            'status'          => 'revoked',
        ]);

        Passport::actingAs($grantee, [], 'api-guard');
        $response = $this
            ->getJson('/api/v1/emergency-contacts/for-me');

        $response->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_unauthorized_user_cannot_access_contacts() : void
    {
        $response = $this->getJson('/api/v1/emergency-contacts');

        $response->assertStatus(401);
    }
}
