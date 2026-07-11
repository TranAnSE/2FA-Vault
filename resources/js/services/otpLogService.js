import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export default {
    list:   (params = {}) => apiClient.get('/otp-logs', { params }),
    clearAll: ()          => apiClient.delete('/otp-logs'),
}
