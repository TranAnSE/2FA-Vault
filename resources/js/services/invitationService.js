import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export default {
    list:       ()          => apiClient.get('/user/invitations'),
    create:     (data)      => apiClient.post('/user/invitations', data),
    revoke:     (id)        => apiClient.delete(`/user/invitations/${id}`),
}
