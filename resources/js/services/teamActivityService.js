import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export default {
    getActivity: (teamId, params = {}) => apiClient.get(`/teams/${teamId}/activity`, { params }),
    exportActivity: (teamId) => apiClient.get(`/teams/${teamId}/activity/export`, { responseType: 'blob' }),
}
