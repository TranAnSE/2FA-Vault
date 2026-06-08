import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export default {
    getContacts:         ()          => apiClient.get('/emergency-contacts'),
    getContactsForMe:    ()          => apiClient.get('/emergency-contacts/for-me'),
    getPendingRequests:  ()          => apiClient.get('/emergency-requests/pending'),
    addContact:          (data)      => apiClient.post('/emergency-contacts', data),
    revokeContact:       (id)        => apiClient.delete(`/emergency-contacts/${id}`),
    requestAccess:       (contactId) => apiClient.post(`/emergency-contacts/${contactId}/request`),
    approveRequest:      (id, data)  => apiClient.post(`/emergency-requests/${id}/approve`, data ?? {}),
    denyRequest:         (id)        => apiClient.post(`/emergency-requests/${id}/deny`),
}
