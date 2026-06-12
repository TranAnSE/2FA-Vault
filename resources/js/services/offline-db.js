/**
 * Offline Database Service - IndexedDB for offline data storage
 * Stores encrypted account data and user settings for offline access.
 *
 * Sub-modules:
 * - offline-db-sync-queue.js — Sync queue operations
 * - offline-db-settings.js — Settings operations
 */

import { OfflineDBSyncQueue } from './offline-db-sync-queue'
import { OfflineDBSettings } from './offline-db-settings'

const DB_NAME = '2fauth-vault';
const DB_VERSION = 1;

const STORES = {
  ACCOUNTS: 'accounts',
  SETTINGS: 'settings',
  SYNC_QUEUE: 'syncQueue'
};

class OfflineDb {
  constructor() {
    this.db = null;
    this.isReady = false;

    // Compose sub-modules with a getter that ensures DB is ready
    const getDb = () => this.ensureReady()
    this.syncQueue = new OfflineDBSyncQueue(getDb)
    this.settings = new OfflineDBSettings(getDb)
  }

  /**
   * Initialize IndexedDB
   */
  async init() {
    if (this.isReady) return this.db;

    return new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, DB_VERSION);

      request.onerror = () => {
        console.error('[OfflineDB] Failed to open database:', request.error);
        reject(request.error);
      };

      request.onsuccess = () => {
        this.db = request.result;
        this.isReady = true;
        if (import.meta.env.DEV) console.log('[OfflineDB] Database opened successfully');
        resolve(this.db);
      };

      request.onupgradeneeded = (event) => {
        if (import.meta.env.DEV) console.log('[OfflineDB] Upgrading database...');
        const db = event.target.result;

        if (!db.objectStoreNames.contains(STORES.ACCOUNTS)) {
          const accountsStore = db.createObjectStore(STORES.ACCOUNTS, { keyPath: 'id' });
          accountsStore.createIndex('service', 'service', { unique: false });
          accountsStore.createIndex('updatedAt', 'updatedAt', { unique: false });
        }

        if (!db.objectStoreNames.contains(STORES.SETTINGS)) {
          db.createObjectStore(STORES.SETTINGS, { keyPath: 'key' });
        }

        if (!db.objectStoreNames.contains(STORES.SYNC_QUEUE)) {
          const syncStore = db.createObjectStore(STORES.SYNC_QUEUE, {
            keyPath: 'id', autoIncrement: true
          });
          syncStore.createIndex('timestamp', 'timestamp', { unique: false });
        }
      };
    });
  }

  /**
   * Ensure database is initialized
   */
  async ensureReady() {
    if (!this.isReady) await this.init();
    return this.db;
  }

  /**
   * Save accounts to offline storage (encrypted)
   */
  async saveAccounts(accounts) {
    const db = await this.ensureReady();

    return new Promise((resolve, reject) => {
      const transaction = db.transaction([STORES.ACCOUNTS], 'readwrite');
      const store = transaction.objectStore(STORES.ACCOUNTS);

      store.clear();
      accounts.forEach(account => { store.put({ ...account, cachedAt: Date.now() }) });

      transaction.oncomplete = () => {
        if (import.meta.env.DEV) console.log('[OfflineDB] Saved', accounts.length, 'accounts');
        resolve();
      };

      transaction.onerror = () => {
        console.error('[OfflineDB] Failed to save accounts:', transaction.error);
        reject(transaction.error);
      };
    });
  }

  /**
   * Get all cached accounts
   */
  async getAccounts() {
    const db = await this.ensureReady();

    return new Promise((resolve, reject) => {
      const transaction = db.transaction([STORES.ACCOUNTS], 'readonly');
      const store = transaction.objectStore(STORES.ACCOUNTS);
      const request = store.getAll();

      request.onsuccess = () => {
        if (import.meta.env.DEV) console.log('[OfflineDB] Retrieved', request.result.length, 'accounts');
        resolve(request.result);
      };

      request.onerror = () => {
        console.error('[OfflineDB] Failed to get accounts:', request.error);
        reject(request.error);
      };
    });
  }

  /**
   * Get single account by ID
   */
  async getAccount(id) {
    const db = await this.ensureReady();

    return new Promise((resolve, reject) => {
      const transaction = db.transaction([STORES.ACCOUNTS], 'readonly');
      const store = transaction.objectStore(STORES.ACCOUNTS);
      const request = store.get(id);

      request.onsuccess = () => resolve(request.result);
      request.onerror = () => {
        console.error('[OfflineDB] Failed to get account:', request.error);
        reject(request.error);
      };
    });
  }

  /**
   * Clear all offline data
   */
  async clearAll() {
    const db = await this.ensureReady();

    return new Promise((resolve, reject) => {
      const transaction = db.transaction(
        [STORES.ACCOUNTS, STORES.SETTINGS, STORES.SYNC_QUEUE], 'readwrite'
      );

      transaction.objectStore(STORES.ACCOUNTS).clear();
      transaction.objectStore(STORES.SETTINGS).clear();
      transaction.objectStore(STORES.SYNC_QUEUE).clear();

      transaction.oncomplete = () => {
        if (import.meta.env.DEV) console.log('[OfflineDB] All data cleared');
        resolve();
      };

      transaction.onerror = () => {
        console.error('[OfflineDB] Failed to clear data:', transaction.error);
        reject(transaction.error);
      };
    });
  }

  /**
   * Get database stats
   */
  async getStats() {
    const db = await this.ensureReady();
    const stats = {};

    for (const storeName of Object.values(STORES)) {
      const transaction = db.transaction([storeName], 'readonly');
      const store = transaction.objectStore(storeName);
      const countRequest = store.count();

      stats[storeName] = await new Promise((resolve) => {
        countRequest.onsuccess = () => resolve(countRequest.result);
      });
    }

    return stats;
  }

  /**
   * Close database connection
   */
  close() {
    if (this.db) {
      this.db.close();
      this.db = null;
      this.isReady = false;
      if (import.meta.env.DEV) console.log('[OfflineDB] Database closed');
    }
  }

  // --- Backward-compatible delegation to sub-modules ---

  queueSync(action, data) { return this.syncQueue.queueSync(action, data) }
  getSyncQueue() { return this.syncQueue.getSyncQueue() }
  clearSyncQueue() { return this.syncQueue.clearSyncQueue() }
  removeSyncItem(idbKey) { return this.syncQueue.removeSyncItem(idbKey) }
  updateSyncItem(idbKey, updates) { return this.syncQueue.updateSyncItem(idbKey, updates) }

  saveSetting(key, value) { return this.settings.saveSetting(key, value) }
  getSetting(key, defaultValue) { return this.settings.getSetting(key, defaultValue) }
  getAllSettings() { return this.settings.getAllSettings() }
}

// Export singleton instance
export default new OfflineDb();
