import twofaccountService from '@/services/twofaccountService'
import { useNotify } from '@2fauth/ui'
import { useErrorHandler } from '@2fauth/stores'

/**
 * Composable for QR code upload and URI prefill logic.
 *
 * @param {object} form - Reactive form instance
 * @param {object} options - { tempIcon, showAlternatives, showAdvancedForm }
 */
export function useQrUpload(form, options = {}) {
    const notify = useNotify()
    const { t } = useI18n()
    const errorHandler = useErrorHandler()
    const uri = ref()

    /**
     * Sends a QR code to backend for decoding and prefills the form with the QR data
     */
    function uploadQrcode(qrcodeForm, qrcodeInput) {
        qrcodeForm.qrcode = qrcodeInput.files[0]

        qrcodeForm.upload('/api/v1/qrcode/decode', { returnError: true })
            .then(response => {
                uri.value = response.data.data

                twofaccountService.preview(uri.value, { returnError: true }).then(response => {
                    form.fill(response.data)
                    form.group_id = 0
                    if (options.tempIcon) {
                        options.tempIcon.value = response.data.icon ? response.data.icon : null
                    }
                })
                .catch(error => {
                    if (error.response.status === 422) {
                        if (error.response.data.errors.uri) {
                            if (options.showAlternatives) options.showAlternatives.value = true
                        }
                        else notify.alert({ text: t(error.response.data.message) })
                    } else {
                        errorHandler.show(error)
                    }
                })
            })
            .catch(error => {
                if (error.response.status !== 422) {
                    notify.alert({ text: error.response.data.message })
                }
            })
    }

    return { uri, uploadQrcode }
}
