import { defineStore } from 'pinia'
import httpClientFactory from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export const useBackupStore = defineStore('backup', {
    state: () => ({
        lastBackupDate: null,
        isExporting: false,
        isImporting: false,
        info: {
            has_backup: false,
            last_backup_at: null,
            days_since_backup: null,
            should_backup: true,
        }
    }),

    getters: {
        needsBackup: (state) => {
            return state.info.should_backup || !state.info.has_backup
        },
    },

    actions: {
        /**
         * Export encrypted backup
         * 
         * @param {string} masterPassword - User's master password
         * @returns {Promise}
         */
        async exportBackup(masterPassword) {
            this.isExporting = true

            try {
                const response = await apiClient.post('/backups/export', {
                    password: masterPassword,
                })

                this.lastBackupDate = new Date().toISOString()

                return response.data
            } finally {
                this.isExporting = false
            }
        },

        /**
         * Import encrypted backup
         * 
         * @param {File} file - Backup file
         * @param {string} masterPassword - User's master password
         * @param {string} mode - 'merge' or 'replace'
         * @returns {Promise}
         */
        async importBackup(file, masterPassword, conflictResolution = 'skip', importGroups = true) {
            this.isImporting = true

            try {
                const formData = new FormData()
                formData.append('backup_file', file)
                formData.append('conflict_resolution', conflictResolution)
                formData.append('import_groups', importGroups ? '1' : '0')
                if (masterPassword) {
                    formData.append('password', masterPassword)
                }

                const response = await apiClient.post('/backups/import', formData, {
                    headers: {
                        'Content-Type': 'multipart/form-data'
                    }
                })

                return response.data
            } finally {
                this.isImporting = false
            }
        },

        /**
         * Get backup metadata from file
         * 
         * @param {File} file - Backup file
         * @returns {Promise}
         */
        async getBackupMetadata(file) {
            const formData = new FormData()
            formData.append('backup_file', file)
            
            const response = await apiClient.post('/backups/metadata', formData, {
                headers: {
                    'Content-Type': 'multipart/form-data'
                }
            })
            
            return response.data
        },

        /**
         * Fetch user's backup info
         * 
         * @returns {Promise}
         */
        async fetchInfo() {
            try {
                const response = await apiClient.get('/backups/info')
                this.info = response.data
                
                if (this.info.last_backup_at) {
                    this.lastBackupDate = this.info.last_backup_at
                }
                
                return this.info
            } catch (error) {
                console.error('Failed to fetch backup info:', error)
                throw error
            }
        },

    }
})
