import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export default {
    list:           ()          => apiClient.get('/secure-notes'),
    get:            (id)        => apiClient.get(`/secure-notes/${id}`),
    create:         (data)      => apiClient.post('/secure-notes', data),
    update:         (id, data)  => apiClient.put(`/secure-notes/${id}`, data),
    remove:         (id)        => apiClient.delete(`/secure-notes/${id}`),
}
