import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export default {
    getAll: ()             => apiClient.get('/tags'),
    create: (data)         => apiClient.post('/tags', data),
    update: (id, data)     => apiClient.put(`/tags/${id}`, data),
    delete: (id)           => apiClient.delete(`/tags/${id}`),
    syncForAccount: (accountId, tagIds) => apiClient.post(`/twofaccounts/${accountId}/tags`, { tags: tagIds }),
}
