<?php

namespace Tests\Feature\Services;

use App\Models\TwoFAccount;
use App\Models\User;
use App\Services\EncryptionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * EncryptionServiceDisableTest test class
 *
 * Tests the encryption disable safety feature that prevents
 * disabling E2EE when encrypted accounts exist.
 */
#[CoversClass(EncryptionService::class)]
class EncryptionServiceDisableTest extends FeatureTestCase
{
    private EncryptionService $encryptionService;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encryptionService = new EncryptionService();
        $this->user = User::factory()->create();
    }

    #[Test]
    public function test_disable_encryption_succeeds_when_no_encrypted_accounts(): void
    {
        $this->user->encryption_enabled = true;
        $this->user->encryption_salt = 'test_salt';
        $this->user->encryption_test_value = '{"ciphertext":"test","iv":"test","authTag":"test"}';
        $this->user->encryption_version = 1;
        $this->user->vault_locked = true;
        $this->user->save();

        // No encrypted TwoFAccounts exist
        $result = $this->encryptionService->disableEncryption($this->user);

        $this->assertTrue($result);
        $this->user->refresh();

        $this->assertFalse($this->user->encryption_enabled);
        $this->assertNull($this->user->encryption_salt);
        $this->assertNull($this->user->encryption_test_value);
        $this->assertEquals(0, $this->user->encryption_version);
        $this->assertFalse($this->user->vault_locked);
    }

    #[Test]
    public function test_disable_encryption_fails_when_encrypted_accounts_exist(): void
    {
        $this->user->encryption_enabled = true;
        $this->user->encryption_salt = 'test_salt';
        $this->user->encryption_test_value = '{"ciphertext":"test","iv":"test","authTag":"test"}';
        $this->user->encryption_version = 1;
        $this->user->vault_locked = true;
        $this->user->save();

        // Create an encrypted account
        TwoFAccount::factory()->encrypted()->create([
            'user_id' => $this->user->id,
        ]);

        $result = $this->encryptionService->disableEncryption($this->user);

        $this->assertFalse($result);
    }

    #[Test]
    public function test_disable_encryption_does_not_clear_metadata_when_blocked(): void
    {
        $this->user->encryption_enabled = true;
        $this->user->encryption_salt = 'preserved_salt';
        $this->user->encryption_test_value = '{"ciphertext":"preserved","iv":"preserved","authTag":"preserved"}';
        $this->user->encryption_version = 1;
        $this->user->vault_locked = true;
        $this->user->save();

        // Create encrypted accounts
        TwoFAccount::factory()->count(3)->encrypted()->create([
            'user_id' => $this->user->id,
        ]);

        $this->encryptionService->disableEncryption($this->user);

        $this->user->refresh();

        // Metadata should remain untouched
        $this->assertTrue($this->user->encryption_enabled);
        $this->assertEquals('preserved_salt', $this->user->encryption_salt);
        $this->assertEquals(1, $this->user->encryption_version);
        $this->assertTrue($this->user->vault_locked);
    }

    #[Test]
    public function test_get_encrypted_account_count_returns_correct_count(): void
    {
        // No accounts
        $this->assertEquals(0, $this->encryptionService->getEncryptedAccountCount($this->user));

        // Create unencrypted accounts — should not count
        TwoFAccount::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'encrypted' => false,
        ]);

        $this->assertEquals(0, $this->encryptionService->getEncryptedAccountCount($this->user));

        // Create encrypted accounts — should count
        TwoFAccount::factory()->count(3)->encrypted()->create([
            'user_id' => $this->user->id,
        ]);

        $this->assertEquals(3, $this->encryptionService->getEncryptedAccountCount($this->user));
    }

    #[Test]
    public function test_get_encrypted_account_count_only_counts_own_accounts(): void
    {
        $otherUser = User::factory()->create();

        // Create encrypted accounts for another user
        TwoFAccount::factory()->count(5)->encrypted()->create([
            'user_id' => $otherUser->id,
        ]);

        // Create encrypted accounts for our user
        TwoFAccount::factory()->count(2)->encrypted()->create([
            'user_id' => $this->user->id,
        ]);

        $this->assertEquals(2, $this->encryptionService->getEncryptedAccountCount($this->user));
    }

    #[Test]
    public function test_disable_encryption_succeeds_with_unencrypted_accounts(): void
    {
        $this->user->encryption_enabled = true;
        $this->user->encryption_salt = 'test_salt';
        $this->user->encryption_test_value = '{"ciphertext":"test","iv":"test","authTag":"test"}';
        $this->user->encryption_version = 1;
        $this->user->save();

        // Create only unencrypted accounts — should not block disable
        TwoFAccount::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'encrypted' => false,
        ]);

        $result = $this->encryptionService->disableEncryption($this->user);

        $this->assertTrue($result);
        $this->user->refresh();
        $this->assertFalse($this->user->encryption_enabled);
    }
}
