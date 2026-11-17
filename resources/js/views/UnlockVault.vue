<script setup>
    import Form from '@/components/formElements/Form'
    import { useCryptoStore } from '@/stores/crypto'
    import { useUserStore } from '@/stores/user'
    import { useNotify } from '@2fauth/ui'
    import { useI18n } from 'vue-i18n'
    import { useErrorHandler } from '@2fauth/stores'
    import httpClientFactory from '@/services/httpClientFactory'
    import prfApiService from '@/services/prfApiService.js'
    import { unlockVaultWithPrf } from '@/services/prfService.js'
    import { webauthnService } from '@/services/webauthn/webauthnService.js'
    import { base64ToBytes, purgeBiometricSettings } from '@/services/prfUnlockSupport.js'

    const errorHandler = useErrorHandler()
    const { t } = useI18n()
    const cryptoStore = useCryptoStore()
    const userStore = useUserStore()
    const notify = useNotify()
    const router = useRouter()
    const apiClient = httpClientFactory('api')

    const isPrfUnlocking = ref(false)

    // PRF unlock context (phase 4, red-team F13): fetched non-blocking on
    // mount; 403/429/5xx/network errors are swallowed and the button stays
    // hidden (SSO-only instances get no scary alert).
    const prfContext = ref(null)

    const unlockForm = reactive(new Form({
        masterPassword: '',
        showPassword: false,
    }))

    onMounted(async () => {
        const statusResponse = await apiClient.get('/encryption/status')

        if (!statusResponse.data.encryption_enabled) {
            userStore.encryption_version = 0
            userStore.vault_locked = false
            cryptoStore.disableEncryption()
            router.push({ name: 'accounts' })
            return
        }

        userStore.encryption_version = statusResponse.data.encryption_version
        userStore.vault_locked = statusResponse.data.vault_locked

        if (!statusResponse.data.vault_locked && cryptoStore.isVaultUnlocked) {
            router.push({ name: 'accounts' })
            return
        }

        // PRF availability probe (non-blocking, mirrors the old biometric probe
        // pattern). Also purges the removed biometric_* IndexedDB settings —
        // if the fetch fails the keys are inert anyway since the module is gone.
        prfApiService.getPrfContext().then(response => {
            prfContext.value = response.data
        }).catch(() => {}).finally(() => {
            purgeBiometricSettings().catch(() => {})
        })
    })

    async function handleUnlock() {
        unlockForm.errors.clear()
        unlockForm.startProcessing()

        try {
            const response = await apiClient.get('/encryption/info')

            if (!response.data.encryption_enabled) {
                cryptoStore.disableEncryption()
                userStore.encryption_version = 0
                userStore.vault_locked = false
                router.push({ name: 'accounts' })
                return
            }

            const { encryption_salt, encryption_test_value } = response.data
            const success = await cryptoStore.unlockVault(
                unlockForm.masterPassword,
                encryption_salt,
                encryption_test_value
            )

            if (!success) {
                unlockForm.errors.set('masterPassword', t('error.invalid_master_password'))
                unlockForm.masterPassword = ''
                return
            }

            await apiClient.post('/encryption/verify', {
                verification_result: true,
            })

            userStore.encryption_version = response.data.encryption_version
            userStore.vault_locked = false

            notify.success({ text: t('notification.vault_unlocked') })
            await userStore.initDataStores()
            router.push({ name: 'accounts' })
        } catch (error) {
            if (error.response?.status === 401) {
                unlockForm.errors.set('masterPassword', t('error.invalid_master_password'))
            } else {
                errorHandler.show(error)
            }

            unlockForm.masterPassword = ''
        } finally {
            unlockForm.finishProcessing()
        }
    }

    async function handleLogout() {
        await userStore.logout()
    }

    /**
     * PRF unlock (plan 261114-2101 phase 4): local-challenge passkey
     * assertion → unwrap the vault key → shared store path → server verify.
     *
     * Error mapping is distinct per class (red-team F17):
     * - no PRF result / ceremony failure → "this passkey can't unlock" (warn)
     * - unwrap OK but test-value verification fails → stale-blob guidance
     */
    async function unlockWithPasskey() {
        isPrfUnlocking.value = true

        try {
            const context = prfContext.value
            const saltBytes = base64ToBytes(context.prf_salt)
            const enrollmentIds = context.enrollments.map(e => e.credential_id)

            const { credentialId, prfBits } = await webauthnService.assertWithPrf(enrollmentIds, saltBytes)

            // State recheck after the human-paced ceremony (red-team F16): a
            // kick or lock during the ceremony must NOT repopulate the store.
            // isAuthenticated is the app's canonical session flag (name-based;
            // authGuard/tossOut) — id stays undefined on registration-fresh
            // sessions (the /user register response carries no id, see the
            // TODO in Register.vue), so keying on it would silently no-op
            // passkey unlock there. A parallel unlock flips vault_locked.
            if (!userStore.isAuthenticated || userStore.vault_locked === false) {
                // Review fix: a silent return looks like a dead button when
                // the vault was unlocked (or the session changed) elsewhere
                // mid-ceremony — say so instead.
                notify.info({ text: t('notification.prf_unlock_superseded') })
                return
            }

            // Refetch the context AFTER the ceremony (review fix): the
            // mount-time snapshot can be stale — a master-password rotation
            // elsewhere during the page's lifetime would unwrap+verify
            // against mutually-consistent old blobs/test value and "unlock"
            // into a vault whose secrets fail to decrypt. Fresh data makes
            // a superseded rotation fail cleanly into the stale mapping.
            // A failed refetch refuses to populate the store at all.
            let fresh
            try {
                fresh = (await prfApiService.getPrfContext()).data
            } catch {
                notify.alert({ text: t('notification.prf_unlock_stale') })
                return
            }

            const enrollment = fresh.enrollments.find(e => e.credential_id === credentialId)

            if (!enrollment) {
                notify.warn({ text: t('error.prf_no_result') })
                return
            }

            const rawKey = await unlockVaultWithPrf(prfBits, enrollment)

            // Shared store path — throws on test-value verification failure.
            await cryptoStore.applyUnlockedKey(rawKey, fresh.encryption_salt, fresh.encryption_test_value)

            await apiClient.post('/encryption/verify', {
                verification_result: true,
            })

            userStore.vault_locked = false

            notify.success({ text: t('notification.vault_unlocked') })
            await userStore.initDataStores()
            router.push({ name: 'accounts' })
        } catch (error) {
            if (error?.prf_no_result || error?.webauthn) {
                notify.warn({ text: t('error.prf_no_result') })
            } else if (error?.name === 'PrfError' && error?.kind === 'unwrap_failed') {
                // The wrapped private key would not unwrap — the enrollment
                // blobs no longer match this passkey's PRF output (rotated/
                // replaced blobs), the exact case PrfError exists to flag.
                notify.alert({ text: t('notification.prf_unlock_stale') })
            } else if (error?.message === 'Invalid password' || error?.name === 'OperationError') {
                // Unwrapped fine but the test value would not verify under the
                // recovered key — the stale-blob window (rotation crash).
                notify.alert({ text: t('notification.prf_unlock_stale') })
            } else {
                errorHandler.show(error)
            }
        } finally {
            isPrfUnlocking.value = false
        }
    }

    onBeforeRouteLeave(() => {
        notify.clear()
    })
</script>

<template>
    <StackLayout>
        <template #content>
            <FormWrapper :title="'heading.end_to_end_encryption'" :punchline="'message.vault_locked_desc'">
                <div class="notification is-warning is-light mb-4">
                    {{ $t('message.vault_locked') }}
                </div>

                <form @submit.prevent="handleUnlock" @keydown="unlockForm.onKeydown($event)">
                    <FormField
                        v-model="unlockForm.masterPassword"
                        fieldName="masterPassword"
                        :errorMessage="unlockForm.errors.get('masterPassword')"
                        :inputType="unlockForm.showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        label="field.master_password"
                        help="field.master_password.help"
                    />

                    <div class="field">
                        <label class="checkbox">
                            <input v-model="unlockForm.showPassword" type="checkbox" />
                            {{ $t('label.show_password') }}
                        </label>
                    </div>

                    <FormButtons
                        :isBusy="unlockForm.isBusy"
                        submitLabel="label.unlock_vault"
                        submitId="btnUnlockVault"
                    />
                </form>

                <!-- Passkey (PRF) unlock option — hidden unless enrollments exist -->
                <div v-if="prfContext?.enrollments?.length > 0" class="mt-4">
                    <hr />
                    <VueButton
                        id="btnPrfUnlock"
                        :isLoading="isPrfUnlocking"
                        class="button is-fullwidth"
                        @click="unlockWithPasskey"
                    >
                        {{ $t('label.unlock_with_passkey') }}
                    </VueButton>
                </div>

                <div class="nav-links mt-4">
                    <p>
                        <a class="is-link" @click="handleLogout">{{ $t('label.logout') }}</a>
                    </p>
                </div>

            </FormWrapper>
        </template>
        <template #footer>
            <VueFooter />
        </template>
    </StackLayout>
</template>

<style scoped>
.nav-links {
    text-align: center;
}
</style>
