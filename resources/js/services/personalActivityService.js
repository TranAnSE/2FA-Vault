import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export default {
    list:       (page = 1) => apiClient.get(`/user/activity?page=${page}`),
    clearAll:   ()          => apiClient.delete('/user/activity'),
}
