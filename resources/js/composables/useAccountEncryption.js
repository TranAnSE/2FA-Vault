import cryptoService from '@/services/crypto'
import { useCryptoStore } from '@/stores/crypto'
import { useNotify } from '@2fauth/ui'

/**
 * Composable for E2EE encryption/decryption of account secrets.
 * Encrypts secrets before save, decrypts on edit.
 */
export function useAccountEncryption() {
    const cryptoStore = useCryptoStore()
    const notify = useNotify()
    const { t } = useI18n()

    /**
     * Encrypts a secret string if the vault is unlocked.
     * Returns the encrypted string, or null on failure.
     */
    async function encryptSecret(secret) {
        if (!cryptoStore.isVaultUnlocked || !secret) return secret

        try {
            // cryptoService.encryptSecret returns {ciphertext, iv, authTag}.
            // The server expects the encrypted secret as a JSON STRING of that
            // object (see EncryptionService::setEncryptedSecret @param). Without
            // JSON.stringify the form posts an object and validation rejects it
            // with "The secret field must be a string." (422).
            const encrypted = await cryptoService.encryptSecret(secret, cryptoStore.encryptionKey)
            return JSON.stringify(encrypted)
        } catch (error) {
            notify.error({ text: t('notification.encryption_failed') })
            return null
        }
    }

    /**
     * Decrypts a secret string if the vault is unlocked.
     * Returns the decrypted string, or the original on failure.
     */
    async function decryptSecret(secret) {
        if (!cryptoStore.isVaultUnlocked || !secret) return secret

        try {
            // The server stores/returns the encrypted secret as a JSON STRING
            // ({ciphertext, iv, authTag}). cryptoService.decryptSecret expects
            // the parsed object, so parse first (mirrors cryptoStore.decryptData).
            const encryptedData = typeof secret === 'string' ? JSON.parse(secret) : secret
            return await cryptoService.decryptSecret(encryptedData, cryptoStore.encryptionKey)
        } catch (error) {
            console.error('Failed to decrypt secret:', error)
            return secret
        }
    }

    return { encryptSecret, decryptSecret }
}
