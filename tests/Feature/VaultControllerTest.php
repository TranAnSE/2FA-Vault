<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VaultControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function createEncryptedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'encryption_enabled' => true,
            'encryption_salt' => 'test_salt',
            'encryption_test_value' => '{"ciphertext":"test","iv":"test","authTag":"test"}',
            'encryption_version' => 1,
            'vault_locked' => false,
        ], $attributes));
    }

    public function test_user_can_list_vaults(): void
    {
        $user  = $this->createEncryptedUser();
        $vault = Vault::factory()->for($user)->create(['name' => 'My Vault']);

        $response = $this->actingAs($user, 'api-guard')->getJson('/api/v1/vaults');

        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'My Vault']);
    }

    public function test_user_can_create_vault(): void
    {
        $user = $this->createEncryptedUser();

        $response = $this->actingAs($user, 'api-guard')->postJson('/api/v1/vaults', [
            'name' => 'New Vault',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'New Vault']);

        $this->assertDatabaseHas('vaults', [
            'user_id' => $user->id,
            'name'    => 'New Vault',
        ]);
    }

    public function test_returns_422_on_11th_vault(): void
    {
        $user = $this->createEncryptedUser();
        Vault::factory()->count(10)->for($user)->create();

        $response = $this->actingAs($user, 'api-guard')->postJson('/api/v1/vaults', [
            'name' => 'Vault Eleven',
        ]);

        $response->assertStatus(422);
    }

    public function test_user_can_rename_vault(): void
    {
        $user  = $this->createEncryptedUser();
        $vault = Vault::factory()->for($user)->create(['name' => 'Old Name']);

        $response = $this->actingAs($user, 'api-guard')->putJson("/api/v1/vaults/{$vault->id}", [
            'name' => 'Renamed Vault',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Renamed Vault']);

        $this->assertDatabaseHas('vaults', [
            'id'   => $vault->id,
            'name' => 'Renamed Vault',
        ]);
    }

    public function test_cannot_delete_default_vault(): void
    {
        $user  = $this->createEncryptedUser();
        $vault = Vault::factory()->for($user)->create(['is_default' => true]);

        $response = $this->actingAs($user, 'api-guard')->deleteJson("/api/v1/vaults/{$vault->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('vaults', ['id' => $vault->id]);
    }

    public function test_can_delete_non_default_vault(): void
    {
        $user  = $this->createEncryptedUser();
        $vault = Vault::factory()->for($user)->create(['is_default' => false]);

        $response = $this->actingAs($user, 'api-guard')->deleteJson("/api/v1/vaults/{$vault->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('vaults', ['id' => $vault->id]);
    }

    public function test_vault_ownership_enforced(): void
    {
        $owner      = $this->createEncryptedUser();
        $otherUser  = $this->createEncryptedUser();
        $vault      = Vault::factory()->for($owner)->create();

        $response = $this->actingAs($otherUser, 'api-guard')->putJson("/api/v1/vaults/{$vault->id}", [
            'name' => 'Hijacked Name',
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseHas('vaults', [
            'id'   => $vault->id,
            'name' => $vault->name,
        ]);
    }

    public function test_user_can_lock_vault(): void
    {
        $user  = $this->createEncryptedUser();
        $vault = Vault::factory()->for($user)->create(['is_locked' => false]);

        $response = $this->actingAs($user, 'api-guard')->postJson("/api/v1/vaults/{$vault->id}/lock");

        $response->assertStatus(200);
        $this->assertTrue($vault->fresh()->is_locked);
    }
}
