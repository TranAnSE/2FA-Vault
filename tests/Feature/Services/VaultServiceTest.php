<?php

namespace Tests\Feature\Services;

use App\Models\User;
use App\Models\Vault;
use App\Services\VaultService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

#[CoversClass(VaultService::class)]
class VaultServiceTest extends FeatureTestCase
{
    protected VaultService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new VaultService();
        $this->user    = User::factory()->create();
    }

    #[Test]
    public function test_can_create_vault(): void
    {
        $vault = $this->service->createVault($this->user, 'Personal');

        $this->assertInstanceOf(Vault::class, $vault);
        $this->assertEquals('Personal', $vault->name);
        $this->assertEquals($this->user->id, $vault->user_id);
        $this->assertDatabaseHas('vaults', [
            'id'      => $vault->id,
            'name'    => 'Personal',
            'user_id' => $this->user->id,
        ]);
    }

    #[Test]
    public function test_first_vault_is_default(): void
    {
        $first  = $this->service->createVault($this->user, 'First');
        $second = $this->service->createVault($this->user, 'Second');

        $this->assertTrue($first->fresh()->is_default);
        $this->assertFalse($second->fresh()->is_default);
    }

    #[Test]
    public function test_cannot_create_more_than_ten_vaults(): void
    {
        // Create 10 vaults (the maximum)
        Vault::factory()->count(10)->for($this->user)->create();

        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('Maximum of 10 vaults allowed.');

        $this->service->createVault($this->user, 'Eleventh');
    }

    #[Test]
    public function test_can_rename_vault(): void
    {
        $vault = Vault::factory()->for($this->user)->create(['name' => 'Old Name']);

        $renamed = $this->service->renameVault($vault, 'New Name');

        $this->assertEquals('New Name', $renamed->fresh()->name);
        $this->assertDatabaseHas('vaults', [
            'id'   => $vault->id,
            'name' => 'New Name',
        ]);
    }

    #[Test]
    public function test_can_delete_non_default_vault(): void
    {
        $vault = Vault::factory()->for($this->user)->create([
            'is_default' => false,
        ]);

        $this->service->deleteVault($vault);

        $this->assertDatabaseMissing('vaults', ['id' => $vault->id]);
    }

    #[Test]
    public function test_cannot_delete_default_vault(): void
    {
        $vault = Vault::factory()->for($this->user)->create([
            'is_default' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete the default vault.');

        $this->service->deleteVault($vault);
    }

    #[Test]
    public function test_can_setup_encryption(): void
    {
        $vault = Vault::factory()->for($this->user)->create();

        $this->service->setupEncryption($vault, 'some_salt', '{"ciphertext":"abc","iv":"def","authTag":"ghi"}');

        $vault->refresh();
        $this->assertEquals('some_salt', $vault->encryption_salt);
        $this->assertEquals('{"ciphertext":"abc","iv":"def","authTag":"ghi"}', $vault->encryption_test_value);
        $this->assertEquals(1, $vault->encryption_version);
        $this->assertTrue($vault->is_locked);
    }

    #[Test]
    public function test_can_lock_and_unlock_vault(): void
    {
        $vault = Vault::factory()->for($this->user)->create(['is_locked' => false]);

        // Lock
        $this->service->lock($vault);
        $this->assertTrue($vault->fresh()->is_locked);

        // Unlock
        $this->service->unlock($vault);
        $fresh = $vault->fresh();
        $this->assertFalse($fresh->is_locked);
        $this->assertNotNull($fresh->last_opened_at);
    }
}
