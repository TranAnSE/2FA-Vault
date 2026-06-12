/**
 * Offline DB Settings Operations
 * Handles saving, retrieving, and managing user settings in IndexedDB.
 */

const STORE = 'settings'

export class OfflineDBSettings {
    constructor(getDb) {
        this._getDb = getDb
    }

    /**
     * Save a single setting
     */
    async saveSetting(key, value) {
        const db = await this._getDb()

        return new Promise((resolve, reject) => {
            const transaction = db.transaction([STORE], 'readwrite')
            const store = transaction.objectStore(STORE)
            const request = store.put({ key, value, updatedAt: Date.now() })

            request.onsuccess = () => {
                if (import.meta.env.DEV) console.log('[OfflineDB] Saved setting:', key)
                resolve()
            }

            request.onerror = () => {
                console.error('[OfflineDB] Failed to save setting:', request.error)
                reject(request.error)
            }
        })
    }

    /**
     * Get a single setting
     */
    async getSetting(key, defaultValue = null) {
        const db = await this._getDb()

        return new Promise((resolve, reject) => {
            const transaction = db.transaction([STORE], 'readonly')
            const store = transaction.objectStore(STORE)
            const request = store.get(key)

            request.onsuccess = () => {
                const result = request.result
                resolve(result ? result.value : defaultValue)
            }

            request.onerror = () => {
                console.error('[OfflineDB] Failed to get setting:', request.error)
                reject(request.error)
            }
        })
    }

    /**
     * Get all settings
     */
    async getAllSettings() {
        const db = await this._getDb()

        return new Promise((resolve, reject) => {
            const transaction = db.transaction([STORE], 'readonly')
            const store = transaction.objectStore(STORE)
            const request = store.getAll()

            request.onsuccess = () => {
                const settings = {}
                request.result.forEach(item => { settings[item.key] = item.value })
                resolve(settings)
            }

            request.onerror = () => {
                console.error('[OfflineDB] Failed to get settings:', request.error)
                reject(request.error)
            }
        })
    }
}
