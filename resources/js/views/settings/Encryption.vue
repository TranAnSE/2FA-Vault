<script setup>
    import tabs from './tabs'
    import Form from '@/components/formElements/Form'
    import { useUserStore } from '@/stores/user'
    import { useCryptoStore } from '@/stores/crypto'
    import { useNotify, TabBar } from '@2fauth/ui'
    import { useI18n } from 'vue-i18n'
    import { useErrorHandler } from '@2fauth/stores'
    import httpClientFactory from '@/services/httpClientFactory'
    import biometricService from '@/services/biometric.js'

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

    const biometricSupported  = ref(false)
    const biometricEnrolled   = ref(false)
    const isBiometricEnrolling = ref(false)
    const bioEnrollPassword   = ref('')

    const formUnlock = reactive(new Form({
        masterPassword : '',
    }))
    const formDisable = reactive(new Form({
        password : '',
    }))

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
        biometricService.isSupported().then(s => {
            biometricSupported.value = s
            if (s) biometricService.isRegistered().then(r => { biometricEnrolled.value = r })
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

    async function enrollBiometric() {
        if (!bioEnrollPassword.value) {
            notify.alert({ text: t('error.master_password_required_for_biometric') })
            return
        }
        isBiometricEnrolling.value = true
        try {
            await biometricService.enrollWithMasterPassword(user.email, bioEnrollPassword.value)
            biometricEnrolled.value = true
            bioEnrollPassword.value = ''
            notify.success({ text: t('notification.biometric_enrolled') })
        } catch (e) {
            notify.alert({ text: t('error.biometric_enrollment_failed') + ': ' + e.message })
        } finally {
            isBiometricEnrolling.value = false
        }
    }

    async function unenrollBiometric() {
        if (!confirm(t('confirmation.remove_biometric'))) return
        try {
            await biometricService.remove()
            biometricEnrolled.value = false
            notify.success({ text: t('notification.biometric_removed') })
        } catch (e) {
            notify.alert({ text: t('error.biometric_removal_failed') })
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

                        <!-- Biometric Unlock -->
                        <template v-if="biometricSupported">
                            <h4 class="title is-4 pt-5">{{ $t('heading.biometric_unlock') }}</h4>
                            <div v-if="biometricEnrolled" class="notification is-success is-size-7 mb-3">
                                {{ $t('message.biometric_enrolled') }}
                            </div>
                            <div v-if="!biometricEnrolled">
                                <p class="mb-3 is-size-7">{{ $t('message.biometric_enrollment_desc') }}</p>
                                <div class="field">
                                    <label class="label is-size-7">{{ $t('field.master_password') }}</label>
                                    <input class="input is-small" type="password" v-model="bioEnrollPassword" autocomplete="current-password" />
                                    <p class="help">{{ $t('message.biometric_password_help') }}</p>
                                </div>
                                <VueButton :isLoading="isBiometricEnrolling" @click="enrollBiometric" class="button is-info">
                                    {{ $t('label.enable_biometric') }}
                                </VueButton>
                            </div>
                            <div v-else>
                                <VueButton @click="unenrollBiometric" class="button is-warning is-light">
                                    {{ $t('label.remove_biometric') }}
                                </VueButton>
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
