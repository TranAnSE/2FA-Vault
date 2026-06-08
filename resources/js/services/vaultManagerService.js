import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export default {
    getAll:          ()          => apiClient.get('/vaults'),
    create:          (data)      => apiClient.post('/vaults', data),
    rename:          (id, data)  => apiClient.put(`/vaults/${id}`, data),
    delete:          (id)        => apiClient.delete(`/vaults/${id}`),
    lock:            (id)        => apiClient.post(`/vaults/${id}/lock`),
    setupEncryption: (id, data)  => apiClient.post(`/vaults/${id}/encryption`, data),
}
