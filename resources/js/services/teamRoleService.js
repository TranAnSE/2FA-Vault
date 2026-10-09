import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export default {
    list:   (teamId)          => apiClient.get(`/teams/${teamId}/roles`),
    create: (teamId, data)    => apiClient.post(`/teams/${teamId}/roles`, data),
    update: (teamId, id, data) => apiClient.put(`/teams/${teamId}/roles/${id}`, data),
    remove: (teamId, id)      => apiClient.delete(`/teams/${teamId}/roles/${id}`),
}
