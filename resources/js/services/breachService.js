import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

/**
 * HaveIBeenPwned breach monitoring.
 * Email checks require the user's breachMonitoring opt-in (server-gated).
 */
export default {
    checkEmail(email, useAccountEmail = false) {
        return apiClient.post('/breach/check-email', {
            email: email || undefined,
            use_account_email: useAccountEmail,
        })
    },

    checkService(service) {
        return apiClient.get('/breach/check-service', { params: { service } })
    },
}
