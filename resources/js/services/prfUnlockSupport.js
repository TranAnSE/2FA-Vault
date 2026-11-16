/**
 * PRF unlock support helpers (plan 261114-2101, phase 4).
 *
 * Small UI-adjacent utilities shared by the PRF flows: base64 decoding for
 * the prf_salt and the one-time IndexedDB purge of the removed biometric_*
 * settings (D3 — the biometric module is deleted; its leftover keys are
 * inert but are wiped opportunistically on the first PRF context fetch).
 */

/**
 * Decode a standard base64 string to bytes (the server encodes prf_salt
 * with PHP base64_encode).
 * @param {string} base64
 * @returns {Uint8Array}
 */
export function base64ToBytes(base64) {
    const binString = atob(base64)
    return Uint8Array.from(binString, (char) => char.charCodeAt(0))
}

/**
 * SHA-256 of a string as lowercase hex — the rewrap stale-writer guard
 * expects sha256(encryption_salt) exactly as PHP's hash('sha256', $salt)
 * computes it (UTF-8 bytes of the stored salt string).
 * @param {string} text
 * @returns {Promise<string>} 64-char lowercase hex digest
 */
export async function sha256Hex(text) {
    // globalThis, not a bare `crypto`: unplugin-auto-import rewrites a bare
    // `crypto` identifier into the default export of services/crypto.js
    // (services/** is an auto-import dir), which has no `subtle`.
    const digest = await globalThis.crypto.subtle.digest('SHA-256', new TextEncoder().encode(text))
    return Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join('')
}

/**
 * Idempotent purge of the removed biometric unlock settings from the
 * '2fauth-vault' IndexedDB settings store (keys prefixed 'biometric_').
 * Fire-and-forget: every failure resolves silently — if the DB or store is
 * missing there is nothing to purge and the module that used the keys is
 * deleted anyway.
 */
export function purgeBiometricSettings() {
    return new Promise((resolve) => {
        try {
            const request = indexedDB.open('2fauth-vault')

            request.onsuccess = () => {
                const db = request.result

                try {
                    if (!db.objectStoreNames.contains('settings')) {
                        db.close()
                        resolve()
                        return
                    }

                    const tx = db.transaction(['settings'], 'readwrite')
                    const store = tx.objectStore('settings')
                    // A tx-level error aborts without firing oncomplete —
                    // resolve here too so the promise can't hang (review fix).
                    tx.onerror = () => {
                        db.close()
                        resolve()
                    }
                    const getAllKeys = store.getAllKeys()

                    getAllKeys.onsuccess = () => {
                        const doomed = (getAllKeys.result ?? []).filter((key) => String(key).startsWith('biometric_'))
                        doomed.forEach((key) => store.delete(key))
                        tx.oncomplete = () => {
                            db.close()
                            resolve()
                        }
                    }

                    getAllKeys.onerror = () => {
                        db.close()
                        resolve()
                    }
                } catch {
                    db.close()
                    resolve()
                }
            }

            request.onerror = () => resolve()
        } catch {
            resolve()
        }
    })
}
