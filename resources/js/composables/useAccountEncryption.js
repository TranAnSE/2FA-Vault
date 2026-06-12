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
            return await cryptoService.encryptSecret(secret)
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
            return await cryptoService.decryptSecret(secret)
        } catch (error) {
            console.error('Failed to decrypt secret:', error)
            return secret
        }
    }

    return { encryptSecret, decryptSecret }
}
