import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export default {
    list:       ()          => apiClient.get('/user/sessions'),
    revoke:     (id)        => apiClient.delete(`/user/sessions/${id}`),
    revokeAll:  ()          => apiClient.delete('/user/sessions'),
}
