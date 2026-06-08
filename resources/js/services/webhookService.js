import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export default {
    getAll:           ()          => apiClient.get('/webhooks'),
    getEvents:        ()          => apiClient.get('/webhooks/events'),
    create:           (data)      => apiClient.post('/webhooks', data),
    update:           (id, data)  => apiClient.put(`/webhooks/${id}`, data),
    delete:           (id)        => apiClient.delete(`/webhooks/${id}`),
    test:             (id)        => apiClient.post(`/webhooks/${id}/test`),
    getDeliveries:    (id)        => apiClient.get(`/webhooks/${id}/deliveries`),
}
