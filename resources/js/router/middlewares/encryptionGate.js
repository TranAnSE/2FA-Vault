/**
 * Enforce encryption setup/unlock routing for authenticated users
 * without creating redirect loops.
 */
export default function encryptionGate({ to, stores }) {
    const { user, cryptoStore } = stores

    if (!user.isAuthenticated) {
        return true
    }

    const routeName = to.name
    const isSetupRoute = routeName === 'setup-encryption'
    const isUnlockRoute = routeName === 'unlock-vault'
    const hasEncryption = user.encryption_version > 0
    const requiresSetup = user.e2ee_required === true && !hasEncryption

    if (!hasEncryption) {
        cryptoStore.disableEncryption()
        user.vault_locked = false

        if (requiresSetup && !isSetupRoute) {
            return { name: 'setup-encryption' }
        }

        if (isUnlockRoute) {
            return { name: 'accounts' }
        }

        return true
    }

    if (!cryptoStore.isVaultUnlocked) {
        if (!cryptoStore.isEncryptionEnabled) {
            cryptoStore.enableEncryption(null)
        }

        if (user.vault_locked !== true) {
            user.vault_locked = true
        }

        if (!isUnlockRoute) {
            return { name: 'unlock-vault' }
        }

        return true
    }

    user.vault_locked = false

    if (isUnlockRoute || (isSetupRoute && user.e2ee_required === true)) {
        return { name: 'accounts' }
    }

    return true
}
