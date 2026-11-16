<script setup>
    import tabs from './tabs'
    import userService from '@/services/userService'
    import { webauthnService } from '@/services/webauthn/webauthnService'
    import prfApiService from '@/services/prfApiService.js'
    import { buildEnrollment } from '@/services/prfService.js'
    import { useCryptoStore } from '@/stores/crypto'
    import { base64ToBytes } from '@/services/prfUnlockSupport.js'
    import { useAppSettingsStore } from '@/stores/appSettings'
    import { useUserStore } from '@/stores/user'
    import { useNotify, TabBar } from '@2fauth/ui'
    import { UseColorMode } from '@vueuse/components'
    import { useI18n } from 'vue-i18n'
    import { useErrorHandler } from '@2fauth/stores'
    import { LucideCirclePlus } from 'lucide-vue-next'

    const errorHandler = useErrorHandler()
    const { t } = useI18n()
    const $2fauth = inject('2fauth')
    const user = useUserStore()
    const appSettings = useAppSettingsStore()
    const cryptoStore = useCryptoStore()
    const notify = useNotify()
    const router = useRouter()
    const returnTo = useStorage($2fauth.prefix + 'returnTo', 'accounts')
    const activeLoginForm = useStorage($2fauth.prefix + 'activeLoginForm', 'legacy')

    const credentials = ref([])
    const isFetching = ref(false)

    // PRF unlock (plan 261114-2101, D8): per-row enroll action, fed by the
    // context endpoint. Empty when E2EE is off or SSO-only denies (403) —
    // the PRF UI stays hidden (settings page is still usable).
    const prfEnrolledIds = ref([])
    const isPrfBusyId = ref(null)

    const isDisabled = computed(() => {
        return (appSettings.enableSso && appSettings.useSsoOnly) || user.authenticated_by_proxy
    })

    onMounted(() => {
        fetchCredentials()
        fetchPrfContext()
    })

    watch(() => user.preferences.useWebauthnOnly, () => {
        userService.updatePreference('useWebauthnOnly', user.preferences.useWebauthnOnly).then(response => {
            notify.success({ text: t('notification.setting_saved') })
        })
    })

    /**
     * Register a new security device
     */
    function register() {
        if (isDisabled.value == true) {
            notify.warn({text: t('error.unsupported_with_reverseproxy') })
            return false
        }

        webauthnService.register().then((response) => {
            router.push({ name: 'settings.webauthn.editCredential', params: { credentialId: JSON.parse(response.config.data).id } })
        })
        .catch(error => {
            if ('webauthn' in error) {
                if (error.name == 'is-warning') {
                    notify.warn({ text: t(error.message) })
                }
                else notify.alert({ text: t(error.message) })
            }
            else if( error.response?.status === 422 ) {
                notify.alert({ text: error.response.data.message })
            }
            else {
                errorHandler.show(error)
            }
        })
    }

    /**
     * revoke a credential
     */
    function revokeCredential(credentialId) {
        if(confirm(t('confirmation.revoke_device'))) {
            userService.revokeWebauthnDevice(credentialId).then(response => {
                // Remove the revoked credential from the collection
                credentials.value = credentials.value.filter(a => a.id !== credentialId)

                // Then we disable the useWebauthnOnly preference which is relevant
                // only when at least one device is registered
                if (credentials.value.length == 0) {
                    user.preferences.useWebauthnOnly = false
                }

                notify.success({ text: t('notification.device_revoked') })
            });
        }
    }

    /**
     * Always display a printable name
     */
    function displayName(credential) {
        return credential.alias ? credential.alias : t('label.my_device') + ' (#' + credential.id.substring(0, 10) + ')'
    }

    /**
     * Fetch the PRF unlock context (enrolled ids + salt). Non-blocking: when
     * E2EE is off (400) or SSO-only denies (403) the PRF UI stays hidden.
     */
    function fetchPrfContext() {
        prfApiService.getPrfContext().then(response => {
            prfEnrolledIds.value = response.data.enrollments.map(e => e.credential_id)
        }).catch(() => {
            prfEnrolledIds.value = []
        })
    }

    /**
     * Enroll this passkey for PRF vault unlock (D2 hybrid ceremony):
     * server-challenged assertion (possession proof) with the client-side
     * prf eval riding the same ceremony, then the wrapped keyset upload.
     * Requires the vault unlocked (encryptionKeyRaw present — D4).
     */
    function enrollPrf(credential) {
        if (cryptoStore.encryptionKeyRaw === null) {
            notify.warn({ text: t('error.vault_locked') })
            return
        }

        isPrfBusyId.value = credential.id

        prfApiService.getPrfContext()
            .then(context => {
                const saltBytes = base64ToBytes(context.data.prf_salt)

                return prfApiService.prfEnrollOptions(credential.id)
                    .then(options => webauthnService.assertWithPrfChallenge(options.data, saltBytes))
                    .then(({ assertion, prfBits }) =>
                        buildEnrollment(prfBits, cryptoStore.encryptionKeyRaw)
                            .then(blobs => prfApiService.prfEnroll({
                                credential_id: credential.id,
                                assertion,
                                ...blobs
                            }))
                    )
            })
            .then(() => {
                notify.success({ text: t('notification.prf_enrolled') })
                fetchPrfContext()
            })
            .catch(error => {
                if (error?.prf_no_result || error?.webauthn) {
                    notify.warn({ text: t('error.prf_no_result') })
                } else if (error?.name === 'PrfError') {
                    // All-zero/broken PRF output (rejectAllZero in buildEnrollment).
                    notify.alert({ text: t('error.prf_output_invalid') })
                } else if (error.response?.status === 422) {
                    notify.alert({ text: error.response.data.message })
                } else {
                    errorHandler.show(error)
                }
            })
            .finally(() => {
                isPrfBusyId.value = null
            })
    }

    /**
     * Get all credentials from backend
     */
    function fetchCredentials() {
        isFetching.value = true

        userService.getWebauthnDevices({returnError: true})
        .then(response => {
            credentials.value = response.data
        })
        .catch(error => {
            if( error.response.status === 403 ) {
                // The backend returns a 403 response if the user is authenticated by a reverse proxy
                // or if SSO only is enabled.
                // The form is already disabled (see isDisabled) so we do nothing more here
            }
            else {
                errorHandler.show(error)
            }
        })
        .finally(() => {
            isFetching.value = false
        })
    }

    /**
     * Updates the active login form on local storage
     */
    async function setActiveLoginForm() {
        activeLoginForm.value = 'webauthn'
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
            <TabBar :tabs="tabs" :active-tab="'settings.webauthn.devices'" @tab-selected="(to) => router.push({ name: to })" />
        </template>
        <template #content>
            <FormWrapper>
                <div v-if="isDisabled && user.oauth_provider" class="notification is-warning has-text-centered">
                    {{ $t('message.sso_only_x_settings_are_disabled', { auth_method: 'WebAuthn' }) }}
                </div>
                <div v-if="isDisabled && user.authenticated_by_proxy" class="notification is-warning has-text-centered">
                    {{ $t('message.auth_handled_by_proxy') + ' ' + $t('message.manage_auth_at_proxy_level') }}
                </div>
                <h4 class="title is-4">{{ $t('heading.security_devices') }}</h4>
                <div class="is-size-7-mobile">
                    {{ $t('message.security_devices_legend')}}
                </div>
                <div class="mt-3">
                    <a tabindex="0" class="is-link" @click="register" @keyup.enter="register">
                        <LucideCirclePlus />&nbsp;{{ $t('link.register_a_new_device')}}
                    </a>
                </div>
                <!-- credentials list -->
                <div v-if="credentials.length > 0" class="field">
                    <UseColorMode v-slot="{ mode }">
                        <div v-for="credential in credentials" :key="credential.id" class="group-item is-size-5 is-size-6-mobile">
                            {{ displayName(credential) }}
                            <!-- PRF enroll action / enrolled badge (D8). The badge is a
                                 non-interactive span (review fix) and Refresh re-runs the
                                 enroll ceremony to replace stale blobs — the backend
                                 upserts atomically on re-enroll. -->
                            <span
                                v-if="prfEnrolledIds.includes(credential.id)"
                                class="tag is-success is-light is-pulled-right mr-1"
                                :title="$t('label.passkey_unlock_enabled')"
                            >
                                {{ $t('label.passkey_unlock_enabled') }}
                            </span>
                            <button
                                v-else-if="!isDisabled"
                                type="button"
                                class="button tag is-pulled-right mr-1"
                                :class="[mode === 'dark' ? 'is-dark':'is-white', isPrfBusyId === credential.id ? 'is-loading' : '']"
                                :disabled="isPrfBusyId === credential.id"
                                @click="enrollPrf(credential)"
                                :title="$t('label.enable_passkey_unlock')"
                            >
                                {{ $t('label.enable_passkey_unlock') }}
                            </button>
                            <button
                                v-if="prfEnrolledIds.includes(credential.id) && !isDisabled"
                                type="button"
                                class="button tag is-pulled-right mr-1"
                                :class="[mode === 'dark' ? 'is-dark':'is-white', isPrfBusyId === credential.id ? 'is-loading' : '']"
                                :disabled="isPrfBusyId === credential.id"
                                @click="enrollPrf(credential)"
                                :title="$t('tooltip.refresh_prf_enrollment')"
                            >
                                {{ $t('label.refresh') }}
                            </button>
                            <!-- revoke link -->
                            <button type="button" class="button tag is-pulled-right" :class="mode === 'dark' ? 'is-dark':'is-white'" @click="revokeCredential(credential.id)" :title="$t('tooltip.revoke')">
                                {{ $t('label.revoke') }}
                            </button>
                        </div>
                    </UseColorMode>
                    <div class="mt-2 is-size-7 is-pulled-right">
                        {{ $t('message.revoking_a_device_is_permanent')}}
                    </div>
                </div>
                <Spinner :isVisible="isFetching && credentials.length === 0" type="list-loading" />
                <h4 class="title is-4 pt-6">{{ $t('heading.options') }}</h4>
                <div class="field">
                    {{ $t('message.need_a_security_device_to_enable_options')}}
                </div>
                <form>
                    <!-- use webauthn only -->
                    <FormCheckbox
                        v-model="user.preferences.useWebauthnOnly"
                        @update:model-value="val => setActiveLoginForm()"
                        fieldName="useWebauthnOnly"
                        label="field.use_webauthn_only"
                        help="field.use_webauthn_only.help"
                        :isDisabled="isDisabled || credentials.length === 0"
                    />
                    <p class="help">{{ $t('field.use_webauthn_only.help_bis') }}</p>
                    <p class="help mt-3">{{ $t('field.use_webauthn_only.help_ter') }}</p>
                </form>
            </FormWrapper>
        </template>
        <template #footer>
            <VueFooter>
                <template #default>
                    <NavigationButton action="close" @closed="router.push({ name: returnTo })" :current-page-title="$t('title.settings.webauthn.devices')" />
                </template>
            </VueFooter>
        </template>
    </StackLayout>
</template>