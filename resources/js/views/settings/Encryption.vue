<script setup>
    import tabs from './tabs'
    import Form from '@/components/formElements/Form'
    import { useUserStore } from '@/stores/user'
    import { useCryptoStore } from '@/stores/crypto'
    import { useNotify, TabBar } from '@2fauth/ui'
    import { useI18n } from 'vue-i18n'
    import { useErrorHandler } from '@2fauth/stores'
    import httpClientFactory from '@/services/httpClientFactory'
    import { generateSalt, bytesToBase64, deriveKeyMaterial, importAesKey, encryptSecret, createTestValue, verifyPassword } from '@/services/crypto'
    import emergencyService from '@/services/emergencyService'
    import prfApiService from '@/services/prfApiService.js'
    import { rewrapVaultKey } from '@/services/prfService.js'
    import { sha256Hex } from '@/services/prfUnlockSupport.js'

    const errorHandler = useErrorHandler()
    const { t } = useI18n()
    const $2fauth = inject('2fauth')
    const user = useUserStore()
    const cryptoStore = useCryptoStore()
    const notify = useNotify()
    const router = useRouter()
    const returnTo = useStorage($2fauth.prefix + 'returnTo', 'accounts')

    const apiClient = httpClientFactory('api')

    const encryptionStatus = ref(null)
    const isLoading = ref(true)
    const isActionLoading = ref(false)

    // PRF unlock status row (plan 261114-2101, D8): null = hidden (E2EE off,
    // SSO-only 403, or fetch failure — all swallowed, non-blocking).
    const prfStatus = ref(null)

    const formUnlock = reactive(new Form({
        masterPassword : '',
    }))
    const formDisable = reactive(new Form({
        password : '',
    }))
    // B5: master-password rotation form
    const formRotate = reactive(new Form({
        currentPassword : '',
        newPassword     : '',
        confirmPassword : '',
    }))
    const isRotating = ref(false)
    const isRetryingPrf = ref(false)

    // D9 stale-blob state lives in the crypto store (cryptoStore.prfStale):
    // the stale blobs persist server-side across navigations while the retry
    // depends on the session-scoped encryptionKeyRaw, so both must share a
    // lifetime longer than this component's.

    // Computed states
    const isEnabled = computed(() => encryptionStatus.value?.encryption_enabled === true)
    const isLocked = computed(() => encryptionStatus.value?.vault_locked === true)
    const isMandatorySetup = computed(() => encryptionStatus.value?.e2ee_required === true)

    /**
     * Fetch encryption status from server
     */
    async function fetchStatus() {
        isLoading.value = true
        try {
            const response = await apiClient.get('/encryption/status')
            encryptionStatus.value = response.data

            // Sync crypto store with server state
            if (response.data.encryption_enabled) {
                // Fetch salt separately (status doesn't include it)
                const infoResponse = await apiClient.get('/encryption/info')
                cryptoStore.enableEncryption(infoResponse.data.encryption_salt)
            } else {
                cryptoStore.disableEncryption()
            }
        } catch (error) {
            errorHandler.show(error)
        } finally {
            isLoading.value = false
        }
    }

    onMounted(() => {
        fetchStatus()
        prfApiService.getPrfContext().then(response => {
            prfStatus.value = { count: response.data.enrollments.length }
        }).catch(() => {})
    })

    /**
     * Lock the vault (clear encryption key from memory)
     */
    async function lockVault() {
        isActionLoading.value = true
        try {
            await apiClient.post('/encryption/lock')
            cryptoStore.lockVault()
            encryptionStatus.value.vault_locked = true
            // Keep the shared user store in lockstep: the PRF unlock handler
            // (UnlockVault.vue) gates on userStore.vault_locked after its
            // ceremony, and other views read the same flag (plan 261114-2101
            // phase 5 e2e finding — lock left it false, silently no-oping
            // passkey unlock).
            user.vault_locked = true
            formUnlock.masterPassword = ''
            notify.success({ text: t('notification.vault_locked') })
        } catch (error) {
            errorHandler.show(error)
        } finally {
            isActionLoading.value = false
        }
    }

    /**
     * Unlock the vault with master password
     */
    async function unlockVault() {
        isActionLoading.value = true
        try {
            // Get encryption info (salt + test value) for key derivation
            const infoResponse = await apiClient.get('/encryption/info')
            const { encryption_salt, encryption_test_value } = infoResponse.data

            // Derive key and verify password (all client-side, zero-knowledge)
            const isValid = await cryptoStore.unlockVault(
                formUnlock.masterPassword,
                encryption_salt,
                encryption_test_value
            )

            if (!isValid) {
                formUnlock.errors.set('masterPassword', t('error.invalid_master_password'))
                isActionLoading.value = false
                return
            }

            // Confirm unlock to server (zero-knowledge: only send true/false)
            await apiClient.post('/encryption/verify', {
                verification_result: true
            })

            encryptionStatus.value.vault_locked = false
            // Keep the shared user store in lockstep (mirror of lockVault;
            // the encryptionGate self-heals either way — consistency fix).
            user.vault_locked = false
            formUnlock.masterPassword = ''
            notify.success({ text: t('notification.vault_unlocked') })
        } catch (error) {
            if (error.response?.status === 401) {
                formUnlock.errors.set('masterPassword', t('error.invalid_master_password'))
            } else {
                errorHandler.show(error)
            }
        } finally {
            isActionLoading.value = false
        }
    }

    /**
     * Disable E2EE encryption (requires password confirmation)
     */
    async function disableEncryption() {
        if (!confirm(t('confirmation.disable_encryption'))) {
            return
        }

        isActionLoading.value = true
        try {
            await apiClient.delete('/encryption/disable', {
                data: {
                    password: formDisable.password,
                    confirm: true
                }
            })

            cryptoStore.disableEncryption()
            encryptionStatus.value = {
                encryption_enabled: false,
                vault_locked: false
            }
            formDisable.password = ''
            notify.success({ text: t('notification.encryption_disabled') })
        } catch (error) {
            if (error.response?.status === 401) {
                formDisable.errors.set('password', t('error.invalid_password'))
            } else {
                errorHandler.show(error)
            }
        } finally {
            isActionLoading.value = false
        }
    }

    /**
     * B5: rotate the E2EE master password client-side (zero-knowledge).
     *
     * Verifies the current password, decrypts every encrypted secret with the
     * old key, re-encrypts them under a key derived from the new password +
     * a fresh salt, then submits the new credentials and the re-encrypted
     * secrets. Emergency wrapped keys are re-wrapped for the new password so
     * the dead man's switch keeps working after rotation (RT3).
     */
    async function rotateMasterPassword() {
        if (formRotate.newPassword !== formRotate.confirmPassword) {
            formRotate.errors.set('confirmPassword', t('error.passwords_do_not_match'))
            return
        }
        // Single-flight guard (D9): a second submit while a rotation is in
        // flight is dropped (cross-tab races are caught by the rewrap salt
        // guard instead).
        if (isRotating.value) return
        isRotating.value = true
        try {
            // 1. Verify the current password against the stored test value.
            const infoResponse = await apiClient.get('/encryption/info')
            const { encryption_salt: currentSalt, encryption_test_value: currentTestValue } = infoResponse.data
            const oldKeyValid = await cryptoStore.unlockVault(formRotate.currentPassword, currentSalt, currentTestValue)
            if (!oldKeyValid) {
                formRotate.errors.set('currentPassword', t('error.invalid_master_password'))
                return
            }

            // 2. Fetch and decrypt every encrypted account with the old key
            //    (plaintext stays in memory only, never leaves the browser).
            const { data: accounts } = await apiClient.get('/twofaccounts', { params: { withSecret: true } }).catch(() => ({ data: [] }))
            const decryptedAccounts = []
            for (const account of (accounts.data ?? accounts)) {
                if (!account.encrypted) continue
                const decrypted = await cryptoStore.decryptAccountData(account)
                decryptedAccounts.push({ id: account.id, secret: decrypted.secret })
            }

            // 3. Derive the NEW key (new salt) + fresh test value, and
            //    re-encrypt every secret under it in one pass. D9: keep the
            //    raw key bytes — the PRF re-wrap re-encapsulates them without
            //    a second Argon2 run and the store keeps them for later retries.
            // Base64 string, not raw bytes: the credentials POST validates
            // encryption_salt as a string (setupEncryption encodes the same
            // way) and deriveKeyMaterial accepts either form.
            const newSalt = bytesToBase64(generateSalt())
            const newKeyRaw = await deriveKeyMaterial(formRotate.newPassword, newSalt)
            const newKey = await importAesKey(newKeyRaw)
            const newTestValue = await createTestValue(newKey)
            const reEncrypted = []
            for (const item of decryptedAccounts) {
                const envelope = await encryptSecret(item.secret, newKey)
                reEncrypted.push({ id: item.id, secret: JSON.stringify(envelope) })
            }

            // 4. Submit new credentials, then the re-encrypted secrets.
            await apiClient.post('/encryption/credentials', {
                encryption_salt: newSalt,
                encryption_test_value: newTestValue,
            })
            // bulk-secrets validates accounts as required|min:1 (batch
            // contract) — an empty vault has nothing to re-encrypt, skip it.
            if (reEncrypted.length > 0) {
                await apiClient.post('/encryption/bulk-secrets', { accounts: reEncrypted })
            }

            // 5. Re-wrap emergency keys for the new password (updateOrCreate
            // per contact — same flow as designation).
            try {
                const { wrapSecretForMember } = await import('@/services/keySharingService')
                const { data: contacts } = await emergencyService.getContacts()
                for (const contact of (contacts ?? [])) {
                    if (['revoked'].includes(contact.status)) continue
                    const { data: keyInfo } = await emergencyService.granteeKeyInfo({ email: contact.email }).catch(() => ({ data: null }))
                    if (!keyInfo?.public_key) continue
                    await emergencyService.addContact({
                        email: contact.email,
                        wait_days: contact.wait_days,
                        access_type: contact.access_type,
                        encrypted_key: await wrapSecretForMember(formRotate.newPassword, keyInfo.public_key),
                        grantee_public_key_fingerprint: keyInfo.fingerprint,
                    })
                }
            } catch {
                notify.warn({ text: t('warning.emergency_keys_rewrap_failed') })
            }

            // 5b. PRF re-wrap (D9, rotation without ceremony): re-encapsulate
            //     K_new under every enrollment's public key — public-key-only,
            //     no passkey prompt. A failure (network, or a racing tab that
            //     rotated first → 409 salt guard) is non-aborting: the rotation
            //     already succeeded, the stale blob only degrades passkey
            //     unlock (password path is intact) and is flagged for retry.
            try {
                await rotatePrfRewrap(newKeyRaw, newSalt)
                cryptoStore.prfStale = false
            } catch {
                cryptoStore.prfStale = true
                notify.warn({ text: t('notification.prf_unlock_stale') })
            }

            // 6. Refresh the in-memory key to the new credentials — from the
            // raw bytes already in hand (a second Argon2 run is pure waste;
            // review fix) — via the shared verified-store path.
            await cryptoStore.applyUnlockedKey(newKeyRaw, newSalt, newTestValue)
            formRotate.reset()
            notify.success({ text: t('notification.master_password_rotated') })
        } catch (error) {
            errorHandler.show(error)
        } finally {
            isRotating.value = false
        }
    }

    /**
     * PRF re-wrap for a rotation (D9): fetches the enrollment list and
     * re-encapsulates the given raw vault key under each enrollment's
     * public key, then submits the wrappings with the stale-writer guard.
     *
     * The guard carries the hash of the salt THE KEY WAS DERIVED UNDER (not
     * the freshly-fetched current salt — that only covers a microsecond
     * race window), so the server 409s exactly when this in-memory key is
     * no longer the vault key. Belt and braces: the fetched test value must
     * also verify under the raw key before anything is wrapped or sent.
     * @param {Uint8Array} rawKey the raw vault key bytes to re-wrap
     * @param {string} rawKeySalt the base64 salt rawKey was derived under
     * @throws {Error} 'stale key' when the key fails the test-value proof
     */
    async function rotatePrfRewrap(rawKey, rawKeySalt) {
        const { data: context } = await prfApiService.getPrfContext()

        if (!context.enrollments?.length) return

        // Key-ownership proof (review fix): the server's current test value
        // must verify under THIS key — a mismatch means the vault was
        // rotated elsewhere and wrapping this key would corrupt every
        // enrollment.
        const keyOk = await verifyPassword(context.encryption_test_value, await importAesKey(rawKey))
        if (!keyOk) {
            throw new Error('stale key')
        }

        const wrappings = []
        for (const enrollment of context.enrollments) {
            wrappings.push({
                credential_id: enrollment.credential_id,
                wrapped_vault_key: await rewrapVaultKey(enrollment, rawKey),
            })
        }

        await prfApiService.prfRewrap({
            expected_encryption_salt_hash: await sha256Hex(rawKeySalt),
            wrappings,
        })
    }

    /**
     * Retry a failed post-rotation PRF re-wrap from the stale banner,
     * using the in-memory K_new kept by the crypto store. The key's salt
     * is the store's current salt (the two are set together at unlock).
     */
    async function retryPrfRewrap() {
        if (isRetryingPrf.value) return
        if (cryptoStore.encryptionKeyRaw === null || cryptoStore.salt === null) {
            notify.warn({ text: t('error.vault_locked') })
            return
        }
        if (!cryptoStore.prfStale) {
            return
        }

        isRetryingPrf.value = true
        try {
            await rotatePrfRewrap(cryptoStore.encryptionKeyRaw, cryptoStore.salt)
            cryptoStore.prfStale = false
            notify.success({ text: t('notification.prf_unlock_rewrapped') })
        } catch {
            notify.warn({ text: t('notification.prf_unlock_stale') })
        } finally {
            isRetryingPrf.value = false
        }
    }

    onBeforeRouteLeave((to) => {
        if (! to.name.startsWith('settings.')) {
            notify.clear()
        }
    })
</script>

<template>
    <StackLayout>
        <template #header>
            <TabBar :tabs="tabs" :active-tab="'settings.encryption'" @tab-selected="(to) => router.push({ name: to })" />
        </template>
        <template #content>
            <FormWrapper>
                <!-- Loading state -->
                <div v-if="isLoading" class="has-text-centered py-6">
                    <span class="loader"></span>
                </div>

                <template v-else>
                    <!-- Not enabled: show setup prompt -->
                    <template v-if="!isEnabled">
                        <h4 class="title is-4">{{ $t('heading.end_to_end_encryption') }}</h4>
                        <div class="notification is-info">
                            <p class="has-text-weight-semibold">{{ $t('message.e2ee_description') }}</p>
                            <ul class="mt-2 ml-4">
                                <li class="mb-1">{{ $t('message.e2ee_benefit_1') }}</li>
                                <li class="mb-1">{{ $t('message.e2ee_benefit_2') }}</li>
                                <li class="mb-1">{{ $t('message.e2ee_benefit_3') }}</li>
                            </ul>
                        </div>
                        <div class="notification is-warning">
                            {{ $t('message.e2ee_warning') }}
                        </div>
                        <div class="mt-5">
                            <button
                                type="button"
                                class="button is-primary"
                                @click="router.push({ name: 'setup-encryption' })"
                            >
                                {{ $t('label.enable_encryption') }}
                            </button>
                        </div>
                        <p v-if="isMandatorySetup" class="mt-3 is-size-7 has-text-warning-light">
                            {{ $t('message.vault_locked_desc') }}
                        </p>
                    </template>

                    <!-- Enabled: show vault controls -->
                    <template v-else>
                        <h4 class="title is-4">{{ $t('heading.end_to_end_encryption') }}</h4>

                        <!-- Status indicator -->
                        <div class="notification" :class="isLocked ? 'is-warning' : 'is-success'">
                            <p class="has-text-weight-semibold">
                                <span v-if="isLocked">{{ $t('message.vault_locked') }}</span>
                                <span v-else>{{ $t('message.vault_unlocked') }}</span>
                            </p>
                            <p class="mt-2 is-size-7">
                                {{ $t('message.e2ee_version', { version: encryptionStatus.encryption_version }) }}
                            </p>
                        </div>

                        <!-- Lock / Unlock controls -->
                        <h4 class="title is-4 pt-4">{{ $t('heading.vault_control') }}</h4>

                        <!-- Unlocked: show Lock button -->
                        <div v-if="!isLocked" class="block">
                            <p class="mb-3">{{ $t('message.vault_unlocked_desc') }}</p>
                            <button
                                type="button"
                                class="button"
                                :class="{ 'is-loading': isActionLoading }"
                                @click="lockVault"
                            >
                                {{ $t('label.lock_vault') }}
                            </button>
                        </div>

                        <!-- Locked: show Unlock form -->
                        <div v-else class="block">
                            <p class="mb-3">{{ $t('message.vault_locked_desc') }}</p>
                            <form @submit.prevent="unlockVault" @keydown="formUnlock.onKeydown($event)">
                                <FormField v-model="formUnlock.masterPassword" fieldName="masterPassword" :errorMessage="formUnlock.errors.get('masterPassword')" inputType="password" label="field.master_password" autocomplete="current-password" help="field.master_password.help" />
                                <FormButtons :isBusy="isActionLoading" submitLabel="label.unlock_vault" />
                            </form>
                        </div>

                        <!-- Change master password (B5 rotation) -->
                        <h4 class="title is-4 pt-5">{{ $t('heading.rotate_master_password') }}</h4>
                        <p class="mb-3 is-size-7">{{ $t('message.rotate_master_password_desc') }}</p>
                        <form @submit.prevent="rotateMasterPassword" @keydown="formRotate.onKeydown($event)">
                            <FormField v-model="formRotate.currentPassword" fieldName="currentPassword" :errorMessage="formRotate.errors.get('currentPassword')" inputType="password" idSuffix="ForRotation" autocomplete="current-password" label="field.current_password" />
                            <FormField v-model="formRotate.newPassword" fieldName="newPassword" :errorMessage="formRotate.errors.get('newPassword')" inputType="password" idSuffix="ForRotation" autocomplete="new-password" label="field.new_master_password" />
                            <FormField v-model="formRotate.confirmPassword" fieldName="confirmPassword" :errorMessage="formRotate.errors.get('confirmPassword')" inputType="password" idSuffix="ForRotation" autocomplete="new-password" label="field.confirm_new_master_password" />
                            <FormButtons :isBusy="isRotating" submitLabel="label.change_master_password" submitId="btnRotateMasterPassword" />
                        </form>

                        <!-- D9 stale-blob banner: the post-rotation PRF re-wrap failed
                             (the passkey unlock blobs no longer match K) — retry entry point -->
                        <div v-if="cryptoStore.prfStale" class="notification is-warning is-size-7 my-3">
                            {{ $t('notification.prf_unlock_stale') }}
                            <button type="button" class="button is-warning is-light ml-2" :disabled="isRotating || isRetryingPrf" @click="retryPrfRewrap">
                                {{ $t('label.refresh') }}
                            </button>
                        </div>

                        <!-- Passkey (PRF) unlock — status row linking to Settings → Security devices (D8) -->
                        <template v-if="prfStatus !== null">
                            <h4 class="title is-4 pt-5">{{ $t('heading.passkey_unlock') }}</h4>
                            <div v-if="prfStatus.count > 0" class="notification is-success is-size-7 mb-3">
                                {{ $t('label.passkey_unlock_enabled') }}
                            </div>
                            <div class="is-size-7">
                                <router-link :to="{ name: 'settings.webauthn.devices' }" class="is-link">
                                    {{ $t('link.manage_passkey_unlock') }}
                                </router-link>
                            </div>
                        </template>

                        <!-- Disable encryption (danger zone) -->
                        <h4 class="title is-4 pt-6 has-text-danger">{{ $t('heading.danger_zone') }}</h4>
                        <div class="field is-size-7-mobile">
                            <p class="block">{{ $t('message.disable_encryption_desc') }}</p>
                        </div>
                        <form @submit.prevent="disableEncryption" @keydown="formDisable.onKeydown($event)">
                            <input hidden type="text" name="name" :value="user.name" autocomplete="username" />
                            <input hidden type="text" name="email" :value="user.email" autocomplete="email" />
                            <fieldset>
                                <FormField v-model="formDisable.password" fieldName="password" :errorMessage="formDisable.errors.get('password')" inputType="password" idSuffix="ForDisableEncryption" autocomplete="current-password" label="field.current_password" help="field.current_password.help" />
                                <FormButtons :isBusy="isActionLoading" submitLabel="label.disable_encryption" submitId="btnDisableEncryption" color="is-danger" />
                            </fieldset>
                        </form>
                    </template>
                </template>
            </FormWrapper>
        </template>
        <template #footer>
            <VueFooter>
                <template #default>
                    <NavigationButton action="close" @closed="router.push({ name: returnTo })" :current-page-title="$t('title.settings.encryption')" />
                </template>
            </VueFooter>
        </template>
    </StackLayout>
</template>
