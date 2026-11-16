<?php

namespace App\Services;

use App\Models\PasskeyPrfCredential;
use App\Models\SecureNote;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EncryptionService
 *
 * Manages the server-side aspects of E2EE (End-to-End Encryption).
 *
 * ZERO-KNOWLEDGE ARCHITECTURE:
 * - The server NEVER receives the master password or derived encryption key
 * - The server stores only: salt (for key derivation), test value (for verification), version
 * - All encryption/decryption happens CLIENT-SIDE using Web Crypto API
 * - The server handles: setup, status checks, vault lock state, encrypted data storage
 *
 * Client-side crypto (handled by frontend crypto.js):
 * - Key derivation: Argon2id (password + salt -> encryption key)
 * - Encryption: AES-256-GCM (key + plaintext -> {ciphertext, iv, authTag})
 * - The encrypted payload format: {"ciphertext":"base64","iv":"base64","authTag":"base64"}
 */
class EncryptionService
{
    /**
     * Current encryption version
     */
    const CURRENT_VERSION = 1;

    /**
     * Setup E2EE for a user
     *
     * Stores the salt and test value that the client generated.
     * The server never sees the password or encryption key.
     *
     * @param  string  $salt  Base64-encoded salt for Argon2id key derivation
     * @param  string  $testValue  Encrypted test value for zero-knowledge verification
     * @param  int  $version  Encryption version
     */
    public function setupEncryption(User $user, string $salt, string $testValue, int $version = self::CURRENT_VERSION) : bool
    {
        if ($user->encryption_version > 0) {
            return false;
        }

        // PRF lifecycle (plan 261114-2101, D6): a fresh E2EE setup invalidates
        // any pre-existing passkey unlock state — enrollments are wrapped to a
        // vault key that no longer exists and the old salt is meaningless.
        $this->flushPrfUnlockState($user);

        $user->encryption_enabled    = true;
        $user->encryption_salt       = $salt;
        $user->encryption_test_value = $testValue;
        $user->encryption_version    = $version;
        $user->vault_locked          = true;

        return $user->save();
    }

    /**
     * Check if user has E2EE enabled
     */
    public function isEncryptionEnabled(User $user) : bool
    {
        return $user->encryption_enabled === true
            && $user->encryption_version > 0
            && ! is_null($user->encryption_salt)
            && ! is_null($user->encryption_test_value);
    }

    /**
     * Get encryption info for a user (salt + test value for client-side key derivation)
     */
    public function getEncryptionInfo(User $user) : array
    {
        if (! $this->isEncryptionEnabled($user)) {
            return ['encryption_enabled' => false];
        }

        return [
            'encryption_enabled'    => true,
            'encryption_salt'       => $user->encryption_salt,
            'encryption_test_value' => $user->encryption_test_value,
            'encryption_version'    => $user->encryption_version,
            'vault_locked'          => $user->vault_locked,
        ];
    }

    /**
     * Lock the vault
     */
    public function lockVault(User $user) : bool
    {
        if (! $this->isEncryptionEnabled($user)) {
            return false;
        }

        $user->vault_locked = true;

        return $user->save();
    }

    /**
     * Unlock the vault after successful client-side verification
     */
    public function unlockVault(User $user) : bool
    {
        $user->vault_locked = false;

        return $user->save();
    }

    /**
     * Disable E2EE for a user
     *
     * Refuses to disable if the user has encrypted accounts that would
     * become permanently inaccessible without their encryption metadata.
     */
    public function disableEncryption(User $user) : bool
    {
        $encryptedCount = $this->getEncryptedAccountCount($user);

        if ($encryptedCount > 0) {
            Log::warning('Cannot disable encryption: user has encrypted accounts', [
                'user_id'         => $user->id,
                'encrypted_count' => $encryptedCount,
            ]);

            return false;
        }

        $user->encryption_enabled    = false;
        $user->encryption_salt       = null;
        $user->encryption_test_value = null;
        $user->encryption_version    = 0;
        $user->vault_locked          = false;

        // PRF lifecycle (plan 261114-2101, D6): passkey unlock dies with E2EE
        // — wipe enrollments + salt so no stale wrapped blobs survive.
        $this->flushPrfUnlockState($user);

        return $user->save();
    }

    /**
     * Wipe the user's PRF unlock state (enrollments + salt).
     *
     * Called by setupEncryption() and disableEncryption() (plan 261114-2101
     * phase 2, red-team F8): stale enrollments must never survive an E2EE
     * lifecycle transition, because their wrapped blobs reference vault-key
     * material the user no longer holds.
     */
    protected function flushPrfUnlockState(User $user) : void
    {
        PasskeyPrfCredential::where('user_id', $user->id)->delete();

        $user->prf_salt = null;
    }

    /**
     * Get count of encrypted accounts for a user
     *
     * B10: also counts encrypted Secure Notes — disabling E2EE while notes
     * carry user-key-encrypted content would orphan them permanently, so the
     * disable guard must refuse in that case too.
     */
    public function getEncryptedAccountCount(User $user) : int
    {
        $accounts = TwoFAccount::where('user_id', $user->id)
            ->where('encrypted', true)
            ->count();

        // Secure notes carry no "encrypted" flag: under user E2EE the client
        // stores the title/content as JSON crypto envelopes ({ciphertext,...}).
        // Detect that shape server-side (the plaintext itself stays hidden).
        $notes = SecureNote::where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('title', 'like', '{"ciphertext"%')
                    ->orWhere('content', 'like', '{"ciphertext"%');
            })
            ->count();

        return $accounts + $notes;
    }

    /**
     * Get encryption status summary
     */
    public function getEncryptionStatus(User $user) : array
    {
        $encryptionEnabled = $this->isEncryptionEnabled($user);
        $e2eeRequired      = $this->isEncryptionRequired($user);

        return [
            'encryption_enabled'  => $encryptionEnabled,
            'encryption_version'  => $user->encryption_version,
            'vault_locked'        => $encryptionEnabled ? $user->vault_locked : false,
            'has_backup'          => ! is_null($user->last_backup_at),
            'last_backup_at'      => $user->last_backup_at?->toIso8601String(),
            'e2ee_required'       => $e2eeRequired,
            'should_prompt_setup' => ! $encryptionEnabled && $e2eeRequired,
        ];
    }

    public function isEncryptionRequired(User $user) : bool
    {
        return config('2fauth.settings.enforceMandatoryEncryption', false)
            && ! $this->isEncryptionEnabled($user);
    }

    /**
     * Update encryption salt (for password change / re-encryption)
     *
     * When a user changes their master password:
     * 1. Client derives new key from new password + new salt
     * 2. Client re-encrypts test value with new key
     * 3. Client sends new salt + new test value here
     * 4. Client re-encrypts all secrets with new key (separate endpoint)
     *
     * @param  string  $newSalt  New base64-encoded salt
     * @param  string  $newTestValue  New encrypted test value
     * @param  int|null  $newVersion  Optional new encryption version
     */
    public function updateEncryptionCredentials(User $user, string $newSalt, string $newTestValue, ?int $newVersion = null) : bool
    {
        if (! $this->isEncryptionEnabled($user)) {
            return false;
        }

        $user->encryption_salt       = $newSalt;
        $user->encryption_test_value = $newTestValue;

        if ($newVersion !== null) {
            $user->encryption_version = $newVersion;
        }

        return $user->save();
    }

    /**
     * Store an encrypted secret for a TwoFAccount
     *
     * The server receives the encrypted payload from the client and stores it as-is.
     * The server NEVER decrypts this data.
     *
     * @param  string  $encryptedSecret  JSON string: {"ciphertext":"...","iv":"...","authTag":"..."}
     */
    public function storeEncryptedSecret(TwoFAccount $account, string $encryptedSecret) : bool
    {
        $account->secret    = $encryptedSecret;
        $account->encrypted = true;

        return $account->save();
    }

    /**
     * Validate encrypted payload structure
     *
     * Checks that the payload has the expected format without decrypting it.
     */
    public function validateEncryptedPayload(string $payload) : bool
    {
        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return false;
        }

        $requiredFields = ['ciphertext', 'iv', 'authTag'];
        foreach ($requiredFields as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bulk update encrypted secrets (for re-encryption on password change)
     *
     * The client re-encrypts all secrets with the new key and sends them here.
     *
     * @param  array  $encryptedAccounts  Array of [{id: int, secret: string}]
     * @return array Results with counts
     */
    public function bulkUpdateEncryptedSecrets(User $user, array $encryptedAccounts) : array
    {
        $updated = 0;
        $failed  = 0;
        $errors  = [];

        DB::beginTransaction();

        try {
            foreach ($encryptedAccounts as $accountData) {
                try {
                    $account = TwoFAccount::where('id', $accountData['id'])
                        ->where('user_id', $user->id)
                        ->first();

                    if (! $account) {
                        $failed++;
                        $errors[] = "Account {$accountData['id']} not found";

                        continue;
                    }

                    if (! $this->validateEncryptedPayload($accountData['secret'])) {
                        $failed++;
                        $errors[] = "Invalid encrypted payload for account {$accountData['id']}";

                        continue;
                    }

                    $account->secret    = $accountData['secret'];
                    $account->encrypted = true;
                    $account->save();
                    $updated++;
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = "Failed to update account {$accountData['id']}: {$e->getMessage()}";
                }
            }

            DB::commit();

            return [
                'updated' => $updated,
                'failed'  => $failed,
                'errors'  => $errors,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get count of encrypted vs unencrypted accounts for a user
     */
    public function getEncryptionStats(User $user) : array
    {
        $stats = TwoFAccount::where('user_id', $user->id)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN encrypted = 1 THEN 1 ELSE 0 END) as encrypted_count')
            ->first();

        $total     = (int) $stats->total;
        $encrypted = (int) $stats->encrypted_count;

        return [
            'total_accounts'       => $total,
            'encrypted_accounts'   => $encrypted,
            'unencrypted_accounts' => $total - $encrypted,
            'fully_encrypted'      => $total > 0 && $encrypted === $total,
        ];
    }
}
