<script setup>
    import Form from '@/components/formElements/Form'
    import { useUserStore } from '@/stores/user'
    import { useBusStore } from '@/stores/bus'
    import { useNotify } from '@2fauth/ui'
    import { useTwofaccounts } from '@/stores/twofaccounts'
    import { useI18n } from 'vue-i18n'

    const { t } = useI18n()
    const router = useRouter()
    const user = useUserStore()
    const bus = useBusStore()
    const notify = useNotify()
    const twofaccounts = useTwofaccounts()

    const qrcodeInput = ref(null)
    const qrcodeInputLabel = ref(null)
    const form = reactive(new Form({
        qrcode: null,
        inputFormat: 'fileUpload',
    }))
    const isPasting = ref(false)
    const isUploading = ref(false)


    /**
     * Upload the submitted QR code file to the backend for decoding, then route the user
     * to the Create or Import form with decoded URI to prefill the form
     *
     * @param {File | undefined} file
     */
    function submitQrCode(file) {
        form.clear()
        form.qrcode = file ?? qrcodeInput.value.files[0]

        form.upload('/api/v1/qrcode/decode', { returnError: true }).then(response => {
            if (response.data.data.slice(0, 33).toLowerCase() === "otpauth-migration://offline?data=") {
                bus.migrationUri = response.data.data
                router.push({ name: 'importAccounts' })
            }
            else {
                bus.decodedUri = response.data.data
                router.push({ name: 'createAccount' })
            }
        })
        .catch(error => {
            if (error.response.status !== 422) {
                notify.alert({ text: error.response.data.message })
            }
        })
        .finally(() => {
            isPasting.value = false
            isUploading.value = false
        })
    }

    /**
     * Push user to the dedicated capture view for live scan
     */
    function capture() {
        router.push({ name: 'capture' });
    }

    /**
     * Checks if the pasted thig is an image, and if yes, submits it to {@link submitQrCode}
     * @param {ClipboardEvent} ev
     */
    function handlePaste(ev) {
        for (let i = 0; i < ev.clipboardData.files.length; i++) {
            const file = ev.clipboardData.files[i];
            if (file.type.indexOf('image/') !== 0) {
                // file is not an image
                break;
            }

            isPasting.value = true
            submitQrCode(file);
            return
        }

        notify.warn({ text: t('message.no_image_found_in_clipboard') })
    }

    /**
     * Triggers decoding of uploaded qr code
     */
    function handleUploadQR() {        
        isUploading.value = true
        submitQrCode()
    }

    onMounted(() => {
        if( user.preferences.useDirectCapture && user.preferences.defaultCaptureMode === 'upload' ) {
            qrcodeInputLabel.value.click()
        }
        if (import.meta.env.DEV) console.log('mounting', handlePaste);
        document.addEventListener('paste', handlePaste);
    })

    onUnmounted(() => {
        document.removeEventListener('paste', handlePaste);
    });
</script>

<template>
    <StackLayout :is-vertical-centered="true">
        <template #content>
            <Spinner v-if="isPasting" :type="'fullscreen-overlay'" :isVisible="true" message="message.parsing_data" />
            <div v-else class="has-text-centered">
                <!-- trailer phrase that invite to add an account -->
                <div :class="{ 'is-hidden' : twofaccounts.count !== 0 }">
                    {{ $t('message.no_account_here') }}<br>
                    {{ $t('message.add_first_account') }}
                </div>
                <!-- Livescan button -->
                <div class="quick-uploader-wrapper p-0 mt-6 mb-5" >
                    <div class="quick-uploader-background"></div>
                    <div class="quick-uploader-button is-align-content-center">
                        <!-- upload a qr code (with basic file field and backend decoding) -->
                        <label role="button" tabindex="0" v-if="user.preferences.useBasicQrcodeReader" class="button is-link is-medium is-rounded is-main"  :class="{ 'is-loading' : isUploading }" ref="qrcodeInputLabel" @keyup.enter="qrcodeInputLabel.click()">
                            <input aria-hidden="true" tabindex="-1" class="file-input" type="file" accept="image/*" v-on:change="submitQrCode" ref="qrcodeInput">
                            {{ $t('label.upload_qrcode') }}
                        </label>
                        <!-- scan button that launch camera stream -->
                        <button v-else type="button" class="button is-link is-medium is-rounded is-main" @click="capture()">
                            {{ $t('label.scan_qrcode') }}
                        </button>
                    </div>
                    <FormFieldError v-if="form.errors.hasAny('qrcode')" :error="form.errors.get('qrcode')" :field="'qrcode'" />
                </div>
                <!-- alternative methods -->
                <div class="block light-or-darker">{{ $t('message.alternative_methods') }}</div>
                <!-- upload a qr code -->
                <div class="block has-text-link" v-if="!user.preferences.useBasicQrcodeReader">
                    <label role="button" tabindex="0" class="button is-link is-outlined is-rounded" :class="{ 'is-loading' : isUploading }" ref="qrcodeInputLabel" @keyup.enter="qrcodeInputLabel.click()">
                        <input aria-hidden="true" tabindex="-1" class="file-input" type="file" accept="image/*" v-on:change="handleUploadQR" ref="qrcodeInput">
                        {{ $t('label.upload_qrcode') }}
                    </label>
                </div>
                <!-- link to advanced form -->
                <div class="block has-text-link">
                    <RouterLink class="button is-link is-outlined is-rounded" :to="{ name: 'createAccount' }" >
                        {{ $t('link.use_advanced_form') }}
                    </RouterLink>
                </div>
                <!-- link to import view -->
                <div class="block has-text-link">
                    <RouterLink id="btnImport" class="button is-link is-outlined is-rounded" :to="{ name: 'importAccounts' }" >
                        {{ $t('label.import') }}
                    </RouterLink>
                </div>
                <!-- paste message -->
                <div class="block">
                    {{ $t('message.you_can_also_paste') }}<br>
                </div>
            </div>
        </template>
        <template #footer>
            <VueFooter>
                <template #default>
                    <NavigationButton v-if="!twofaccounts.isEmpty" action="back" @goback="router.push({ name: 'accounts' })" :previous-page-title="$t('title.accounts')" />
                </template>
            </VueFooter>
        </template>
    </StackLayout>
</template>
