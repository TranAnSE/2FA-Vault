import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export default {
    getDashboard: (period = '24h') => apiClient.get('/admin/rate-limits', { params: { period } }),
}
