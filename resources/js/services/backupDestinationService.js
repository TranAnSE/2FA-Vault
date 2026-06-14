import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export default {
    list:       ()          => apiClient.get('/user/backup-destinations'),
    create:     (data)      => apiClient.post('/user/backup-destinations', data),
    update:     (id, data)  => apiClient.put(`/user/backup-destinations/${id}`, data),
    remove:     (id)        => apiClient.delete(`/user/backup-destinations/${id}`),
    test:       (id)        => apiClient.post(`/user/backup-destinations/${id}/test`),
}
