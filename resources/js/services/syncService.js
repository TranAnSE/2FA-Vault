/**
 * SyncService — coordinates Background Sync for offline mutations.
 * Queues operations in IndexedDB and registers a Background Sync tag.
 * Falls back to immediate sync via service worker message on browsers
 * that don't support the Background Sync API.
 */

import offlineDb from './offline-db.js'

const SYNC_TAG = 'vault-sync'

export const syncService = {
    /**
     * Queue an operation and request a background sync.
     * @param {'CREATE_ACCOUNT'|'UPDATE_ACCOUNT'|'DELETE_ACCOUNT'|'UPDATE_COUNTER'} action
     * @param {object} data
     */
    async queue(action, data) {
        await offlineDb.queueSync(action, data)
        await this.requestSync()
    },

    /**
     * Register a Background Sync tag (or trigger immediate sync as fallback).
     */
    async requestSync() {
        if (!('serviceWorker' in navigator)) return

        const reg = await navigator.serviceWorker.ready.catch(() => null)
        if (!reg) return

        if ('sync' in reg) {
            try {
                await reg.sync.register(SYNC_TAG)
                return
            } catch {
                // Fall through to immediate sync
            }
        }

        // Fallback for Firefox/Safari: tell SW to process queue now
        if (navigator.onLine && reg.active) {
            reg.active.postMessage({ type: 'PROCESS_SYNC_QUEUE' })
        }
    },

    /**
     * Returns pending/failed counts from the sync queue.
     */
    async getStatus() {
        const queue = await offlineDb.getSyncQueue().catch(() => [])
        return {
            pending: queue.filter(i => i.status === 'pending').length,
            failed:  queue.filter(i => i.status === 'failed').length,
            syncing: queue.filter(i => i.status === 'syncing').length,
        }
    },

    /**
     * Listen for SYNC_COMPLETE messages from the service worker.
     */
    onSyncComplete(callback) {
        if (!('serviceWorker' in navigator)) return () => {}
        const handler = (event) => {
            if (event.data?.type === 'SYNC_COMPLETE') callback(event.data)
        }
        navigator.serviceWorker.addEventListener('message', handler)
        return () => navigator.serviceWorker.removeEventListener('message', handler)
    },
}
