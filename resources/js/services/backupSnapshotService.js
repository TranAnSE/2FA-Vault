import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export default {
    list:    ()          => apiClient.get('/backups/snapshots'),
    create:  (data)      => apiClient.post('/backups/snapshots', data),
    remove:  (id)        => apiClient.delete(`/backups/snapshots/${id}`),
    dryRun:  (id, mode)  => apiClient.post(`/backups/snapshots/${id}/dry-run`, { mode }),
    restore: (id, data)  => apiClient.post(`/backups/snapshots/${id}/restore`, data),
}
