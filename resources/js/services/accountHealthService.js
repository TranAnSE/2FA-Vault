import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

/**
 * Account security health scoring (server-visible metadata only; E2EE-safe).
 */
export default {
    getScore(accountId) {
        return apiClient.get('/twofaccounts/' + accountId + '/health')
    },

    getSummary() {
        return apiClient.get('/twofaccounts/health/summary')
    },
}
