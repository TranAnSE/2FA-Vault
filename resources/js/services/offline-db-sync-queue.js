/**
 * Offline DB Sync Queue Operations
 * Handles queuing, retrieving, and managing sync actions for offline-first support.
 */

const STORE = 'syncQueue'

export class OfflineDBSyncQueue {
    constructor(getDb) {
        this._getDb = getDb
    }

    /**
     * Queue an action to sync when back online
     */
    async queueSync(action, data) {
        const db = await this._getDb()

        return new Promise((resolve, reject) => {
            const transaction = db.transaction([STORE], 'readwrite')
            const store = transaction.objectStore(STORE)
            const request = store.add({
                id: crypto.randomUUID(),
                action,
                data,
                timestamp: Date.now(),
                retryCount: 0,
                maxRetries: 3,
                status: 'pending',
            })

            request.onsuccess = () => {
                if (import.meta.env.DEV) console.log('[OfflineDB] Queued sync action:', action)
                resolve(request.result)
            }

            request.onerror = () => {
                console.error('[OfflineDB] Failed to queue sync:', request.error)
                reject(request.error)
            }
        })
    }

    /**
     * Get all queued sync actions
     */
    async getSyncQueue() {
        const db = await this._getDb()

        return new Promise((resolve, reject) => {
            const transaction = db.transaction([STORE], 'readonly')
            const store = transaction.objectStore(STORE)
            const request = store.getAll()

            request.onsuccess = () => resolve(request.result)
            request.onerror = () => {
                console.error('[OfflineDB] Failed to get sync queue:', request.error)
                reject(request.error)
            }
        })
    }

    /**
     * Clear sync queue after successful sync
     */
    async clearSyncQueue() {
        const db = await this._getDb()

        return new Promise((resolve, reject) => {
            const transaction = db.transaction([STORE], 'readwrite')
            const store = transaction.objectStore(STORE)
            const request = store.clear()

            request.onsuccess = () => {
                if (import.meta.env.DEV) console.log('[OfflineDB] Sync queue cleared')
                resolve()
            }

            request.onerror = () => {
                console.error('[OfflineDB] Failed to clear sync queue:', request.error)
                reject(request.error)
            }
        })
    }

    /**
     * Remove a specific item from the sync queue by its IDB key
     */
    async removeSyncItem(idbKey) {
        const db = await this._getDb()
        return new Promise((resolve, reject) => {
            const tx = db.transaction([STORE], 'readwrite')
            const req = tx.objectStore(STORE).delete(idbKey)
            req.onsuccess = () => resolve()
            req.onerror = () => reject(req.error)
        })
    }

    /**
     * Update status and retry count on a sync queue item
     */
    async updateSyncItem(idbKey, updates) {
        const db = await this._getDb()
        return new Promise((resolve, reject) => {
            const tx = db.transaction([STORE], 'readwrite')
            const store = tx.objectStore(STORE)
            const getReq = store.get(idbKey)
            getReq.onsuccess = () => {
                const item = getReq.result
                if (!item) return resolve()
                const putReq = store.put({ ...item, ...updates }, idbKey)
                putReq.onsuccess = () => resolve()
                putReq.onerror = () => reject(putReq.error)
            }
            getReq.onerror = () => reject(getReq.error)
        })
    }
}
