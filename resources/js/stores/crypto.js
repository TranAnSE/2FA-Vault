/**
 * Crypto Store - Manages encryption state and vault locking
 * Pinia store for E2EE encryption key management
 */

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import * as crypto from '@/services/crypto'

export const useCryptoStore = defineStore('crypto', () => {
    // State
    const encryptionKey = ref(null)
    // Raw vault-key bytes (plan 261114-2101, D11): session-long retention so
    // PRF enrollment and password-rotation re-wrapping can use the key
    // without a second Argon2 run. The honest security delta vs today is
    // key EXFILTRATION (not merely in-page use) under script injection —
    // documented in the public docs; rotation after suspected compromise is
    // mandatory guidance. Cleared everywhere encryptionKey is cleared.
    const encryptionKeyRaw = ref(null)
    const isVaultUnlocked = ref(false)
    const encryptionEnabled = ref(false)
    const salt = ref(null)

    // D9 stale-blob flag: set when a post-rotation PRF re-wrap fails (409
    // stale writer or network). Stored HERE — not in the Encryption view —
    // because the stale blobs persist server-side across navigations while
    // the retry depends on encryptionKeyRaw, so the flag must share that
    // session lifetime. Cleared by a successful re-wrap and on reset/lock.
    const prfStale = ref(false)

    // Getters
    const isEncryptionEnabled = computed(() => encryptionEnabled.value)
    const isUnlocked = computed(() => isVaultUnlocked.value)
    const hasEncryptionKey = computed(() => encryptionKey.value !== null)
    
    // Actions
    
    /**
     * Initialize encryption for a user
     * @param {string} masterPassword - User's master password
     * @returns {Promise<{salt: string, testValue: string}>} Salt and test value for server storage
     */
    async function setupEncryption(masterPassword) {
        // Generate salt
        const saltBytes = crypto.generateSalt()
        const saltBase64 = bytesToBase64(saltBytes)

        // Derive raw key material, then the CryptoKey from it (one Argon2 run)
        const rawKey = await crypto.deriveKeyMaterial(masterPassword, saltBytes)
        const key = await crypto.importAesKey(rawKey)

        // Create test value for verification
        const testValue = await crypto.createTestValue(key)

        // Store in memory
        encryptionKey.value = key
        encryptionKeyRaw.value = rawKey
        salt.value = saltBase64
        encryptionEnabled.value = true
        isVaultUnlocked.value = true

        return {
            salt: saltBase64,
            testValue
        }
    }
    
    /**
     * Store a verified unlocked key (password path and PRF path share this).
     *
     * Imports the AES CryptoKey from the raw bytes, verifies the test value,
     * and populates the store. Throws on verification failure — callers own
     * the user-facing error mapping (plan 261114-2101 phase 3, red-team F5).
     * @param {Uint8Array} rawKey - Raw 32-byte vault key
     * @param {string} storedSalt - User's encryption salt (kept in store)
     * @param {string} testValue - Encrypted test value from server
     */
    async function applyUnlockedKey(rawKey, storedSalt, testValue) {
        const key = await crypto.importAesKey(rawKey)

        // Verify by decrypting the test value
        const isValid = await crypto.verifyPassword(testValue, key)

        if (!isValid) {
            throw new Error('Invalid password')
        }

        encryptionKey.value = key
        encryptionKeyRaw.value = rawKey
        salt.value = storedSalt
        encryptionEnabled.value = true
        isVaultUnlocked.value = true
    }

    /**
     * Unlock vault with master password
     * @param {string} masterPassword - User's master password
     * @param {string} storedSalt - Salt from server
     * @param {string} testValue - Encrypted test value from server
     * @returns {Promise<boolean>} True if unlocked successfully
     */
    async function unlockVault(masterPassword, storedSalt, testValue) {
        try {
            // Derive raw key material, then verify + store via the shared path
            const rawKey = await crypto.deriveKeyMaterial(masterPassword, storedSalt)

            await applyUnlockedKey(rawKey, storedSalt, testValue)

            return true
        } catch (error) {
            console.error('Failed to unlock vault:', error)
            return false
        }
    }

    /**
     * Lock the vault (clear encryption key from memory)
     */
    function lockVault() {
        encryptionKey.value = null
        encryptionKeyRaw.value = null
        isVaultUnlocked.value = false
    }
    
    /**
     * Enable encryption state (called when user has encryption setup)
     * @param {string} userSalt - User's encryption salt
     */
    function enableEncryption(userSalt) {
        encryptionEnabled.value = true
        salt.value = userSalt
        isVaultUnlocked.value = false
    }
    
    /**
     * Disable encryption (for users who haven't setup E2EE)
     */
    function disableEncryption() {
        encryptionEnabled.value = false
        encryptionKey.value = null
        encryptionKeyRaw.value = null
        salt.value = null
        isVaultUnlocked.value = false
        prfStale.value = false
    }
    
    /**
     * Encrypt an account before sending to server
     * @param {Object} account - Account object with plaintext secret
     * @returns {Promise<Object>} Account with encrypted secret
     */
    async function encryptAccountData(account) {
        if (!isVaultUnlocked.value || !encryptionKey.value) {
            throw new Error('Vault is locked')
        }
        
        return await crypto.encryptAccount(account, encryptionKey.value)
    }
    
    /**
     * Decrypt an account received from server
     * @param {Object} encryptedAccount - Account with encrypted secret
     * @returns {Promise<Object>} Account with plaintext secret
     */
    async function decryptAccountData(encryptedAccount) {
        if (!isVaultUnlocked.value || !encryptionKey.value) {
            throw new Error('Vault is locked')
        }
        
        return await crypto.decryptAccount(encryptedAccount, encryptionKey.value)
    }
    
    /**
     * Decrypt multiple accounts
     * @param {Array<Object>} accounts - Array of encrypted accounts
     * @returns {Promise<Array<Object>>} Array of decrypted accounts
     */
    async function decryptAccounts(accounts) {
        if (!isVaultUnlocked.value || !encryptionKey.value) {
            throw new Error('Vault is locked')
        }
        
        return await Promise.all(
            accounts.map(account => crypto.decryptAccount(account, encryptionKey.value))
        )
    }
    
    /**
     * Encrypt an arbitrary string (e.g. secure note title/content)
     * @param {string} plaintext - Data to encrypt
     * @returns {Promise<string>} JSON string {ciphertext, iv, authTag}
     */
    async function encryptData(plaintext) {
        if (!isVaultUnlocked.value || !encryptionKey.value) {
            throw new Error('Vault is locked')
        }
        if (plaintext === null || plaintext === undefined) return null
        const encrypted = await crypto.encryptSecret(String(plaintext), encryptionKey.value)
        return JSON.stringify(encrypted)
    }

    /**
     * Decrypt an arbitrary string (e.g. secure note title/content)
     * @param {string} encryptedJson - JSON string {ciphertext, iv, authTag}
     * @returns {Promise<string>} Decrypted plaintext
     */
    async function decryptData(encryptedJson) {
        if (!isVaultUnlocked.value || !encryptionKey.value) {
            throw new Error('Vault is locked')
        }
        if (!encryptedJson) return ''
        const encryptedData = typeof encryptedJson === 'string'
            ? JSON.parse(encryptedJson)
            : encryptedJson
        return await crypto.decryptSecret(encryptedData, encryptionKey.value)
    }

    /**
     * Reset store (for logout)
     */
    function reset() {
        encryptionKey.value = null
        encryptionKeyRaw.value = null
        isVaultUnlocked.value = false
        encryptionEnabled.value = false
        salt.value = null
        prfStale.value = false
    }

    return {
        // State
        encryptionKey,
        encryptionKeyRaw,
        isVaultUnlocked,
        encryptionEnabled,
        salt,
        prfStale,

        // Getters
        isEncryptionEnabled,
        isUnlocked,
        hasEncryptionKey,

        // Actions
        setupEncryption,
        unlockVault,
        applyUnlockedKey,
        lockVault,
        enableEncryption,
        disableEncryption,
        encryptAccountData,
        decryptAccountData,
        decryptAccounts,
        encryptData,
        decryptData,
        reset
    }
})

// Helper function
function bytesToBase64(bytes) {
    const binString = String.fromCharCode(...bytes)
    return btoa(binString)
}
