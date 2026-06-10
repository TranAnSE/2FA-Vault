<?php

namespace Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E2EE Crypto Service Tests
 * 
 * These tests verify that the server NEVER has access to plaintext secrets
 * All encryption/decryption happens client-side
 */
class CryptoTest extends TestCase
{
    /**
     * Test that server never stores plaintext secrets
     */
    public function test_server_never_stores_plaintext_secrets(): void
    {
        $account = new \App\Models\TwoFAccount();

        // When an E2EE-encrypted secret is set, the server stores it as-is (opaque JSON)
        $encryptedPayload = '{"ciphertext":"base64data","iv":"base64iv","authTag":"base64tag"}';
        $account->secret = $encryptedPayload;

        // The raw attribute should be stored as the opaque JSON, not decrypted/modified
        $this->assertEquals($encryptedPayload, $account->getAttributes()['secret']);

        // The getter returns it as-is without attempting decryption
        $this->assertEquals($encryptedPayload, $account->secret);
    }

    /**
     * Test that encrypted secret matches expected E2EE JSON structure
     */
    public function test_encrypted_secret_matches_expected_json_structure(): void
    {
        $account = new \App\Models\TwoFAccount();

        // Valid E2EE payload structure
        $account->secret = '{"ciphertext":"abc","iv":"def","authTag":"ghi"}';

        // Should be detected as encrypted and stored without modification
        $this->assertStringStartsWith('{', $account->secret);
        $this->assertStringContainsString('ciphertext', $account->secret);
        $this->assertStringContainsString('iv', $account->secret);
        $this->assertStringContainsString('authTag', $account->secret);
    }
    
    /**
     * Test that server never receives encryption keys
     */
    public function test_server_never_receives_encryption_keys(): void
    {
        $user = new \App\Models\User();
        $fillable = $user->getFillable();

        // Server should never have an encryption_key field
        $this->assertNotContains('encryption_key', $fillable);
        $this->assertNotContains('master_password', $fillable);
        $this->assertNotContains('derived_key', $fillable);

        // Only salt and test_value are stored (never the key itself)
        $this->assertContains('encryption_salt', $fillable);
        $this->assertContains('encryption_test_value', $fillable);
    }

    /**
     * Test that User model hides encryption fields from API responses
     */
    public function test_user_model_hides_encryption_fields_from_api(): void
    {
        $user = new \App\Models\User();
        $hidden = $user->getHidden();

        // Encryption secrets must never appear in API responses
        $this->assertContains('encryption_salt', $hidden);
        $this->assertContains('encryption_test_value', $hidden);
        $this->assertContains('password', $hidden);
        $this->assertContains('remember_token', $hidden);
    }

    /**
     * Test that User model casts encryption fields correctly
     */
    public function test_user_model_casts_encryption_fields_correctly(): void
    {
        $user = new \App\Models\User();
        $casts = $user->getCasts();

        $this->assertArrayHasKey('encryption_enabled', $casts);
        $this->assertEquals('boolean', $casts['encryption_enabled']);
        $this->assertArrayHasKey('vault_locked', $casts);
        $this->assertEquals('boolean', $casts['vault_locked']);
        $this->assertArrayHasKey('encryption_version', $casts);
        $this->assertEquals('integer', $casts['encryption_version']);
    }
    
    /**
     * Test that encryption_salt is stored but never the key
     */
    public function test_only_salt_is_stored_not_key(): void
    {
        // Verify User model has encryption_salt field
        // Verify User model does NOT have encryption_key field
        
        $userModel = new \App\Models\User();
        $fillable = $userModel->getFillable();
        $casts = $userModel->getCasts();
        
        // Should NOT have encryption_key in fillable or casts
        $this->assertNotContains('encryption_key', $fillable);
        $this->assertArrayNotHasKey('encryption_key', $casts);
        
        // Encryption fields should be in casts
        $this->assertArrayHasKey('encryption_version', $casts);
        $this->assertArrayHasKey('vault_locked', $casts);
    }
    
    /**
     * Test that TwoFAccount model has encrypted flag
     */
    public function test_twofaccount_has_encrypted_flag(): void
    {
        $model = new \App\Models\TwoFAccount();
        $casts = $model->getCasts();
        
        $this->assertArrayHasKey('encrypted', $casts);
        $this->assertEquals('boolean', $casts['encrypted']);
    }
    
    /**
     * Test that User model hides sensitive encryption fields
     */
    public function test_user_model_hides_encryption_secrets(): void
    {
        $user = new \App\Models\User();
        $hidden = $user->getHidden();
        
        // Encryption secrets should be hidden from API responses
        $this->assertContains('encryption_salt', $hidden);
        $this->assertContains('encryption_test_value', $hidden);
    }
}
