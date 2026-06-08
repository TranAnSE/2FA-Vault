/**
 * E2EE Key Sharing Service
 * Handles RSA-OAEP key pair generation, public key registration,
 * and per-member secret wrapping/unwrapping for team sharing.
 *
 * Private key is stored in IndexedDB. Public key is registered on the server.
 * The server never sees plaintext secrets.
 */

import { httpClientFactory } from '@/services/httpClientFactory'

const DB_NAME    = '2fauth-keystore'
const DB_VERSION = 1
const STORE_NAME = 'keys'
const KEY_ID     = 'sharing-key-pair'

const apiClient = httpClientFactory('api')

// ── IndexedDB helpers ──────────────────────────────────────────────────────

function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION)
        req.onupgradeneeded = (e) => {
            e.target.result.createObjectStore(STORE_NAME)
        }
        req.onsuccess = () => resolve(req.result)
        req.onerror   = () => reject(req.error)
    })
}

async function dbGet(key) {
    const db = await openDb()
    return new Promise((resolve, reject) => {
        const tx  = db.transaction(STORE_NAME, 'readonly')
        const req = tx.objectStore(STORE_NAME).get(key)
        req.onsuccess = () => resolve(req.result)
        req.onerror   = () => reject(req.error)
    })
}

async function dbPut(key, value) {
    const db = await openDb()
    return new Promise((resolve, reject) => {
        const tx  = db.transaction(STORE_NAME, 'readwrite')
        const req = tx.objectStore(STORE_NAME).put(value, key)
        req.onsuccess = () => resolve()
        req.onerror   = () => reject(req.error)
    })
}

// ── Encoding helpers ───────────────────────────────────────────────────────

function arrayBufferToBase64(buffer) {
    return btoa(String.fromCharCode(...new Uint8Array(buffer)))
}

function base64ToArrayBuffer(b64) {
    const binary = atob(b64)
    const buf    = new Uint8Array(binary.length)
    for (let i = 0; i < binary.length; i++) buf[i] = binary.charCodeAt(i)
    return buf.buffer
}

// ── Key management ─────────────────────────────────────────────────────────

const RSA_PARAMS = {
    name:           'RSA-OAEP',
    modulusLength:  2048,
    publicExponent: new Uint8Array([0x01, 0x00, 0x01]),
    hash:           'SHA-256',
}

async function generateKeyPair() {
    const keyPair = await crypto.subtle.generateKey(RSA_PARAMS, true, ['encrypt', 'decrypt'])

    const publicKeyRaw  = await crypto.subtle.exportKey('spki',  keyPair.publicKey)
    const privateKeyRaw = await crypto.subtle.exportKey('pkcs8', keyPair.privateKey)

    return {
        publicKeyBase64:  arrayBufferToBase64(publicKeyRaw),
        privateKeyBase64: arrayBufferToBase64(privateKeyRaw),
        publicKey:  keyPair.publicKey,
        privateKey: keyPair.privateKey,
    }
}

/**
 * Ensure the user has a key pair. Generates and registers one if not present.
 */
export async function ensureKeyPair() {
    const stored = await dbGet(KEY_ID)
    if (stored) return stored

    const { publicKeyBase64, privateKeyBase64 } = await generateKeyPair()

    await dbPut(KEY_ID, { publicKeyBase64, privateKeyBase64 })
    await apiClient.post('/user/public-key', { public_key: publicKeyBase64 })

    return { publicKeyBase64, privateKeyBase64 }
}

/**
 * Get the user's private key (CryptoKey) from IndexedDB.
 */
export async function getPrivateKey() {
    const stored = await dbGet(KEY_ID)
    if (!stored?.privateKeyBase64) throw new Error('No private key found. Set up E2EE key sharing first.')

    return crypto.subtle.importKey(
        'pkcs8',
        base64ToArrayBuffer(stored.privateKeyBase64),
        RSA_PARAMS,
        false,
        ['decrypt']
    )
}

// ── Wrap/Unwrap ────────────────────────────────────────────────────────────

/**
 * Encrypt a secret string using a member's public key (Base64 SPKI).
 */
export async function wrapSecretForMember(secret, memberPublicKeyBase64) {
    const publicKey = await crypto.subtle.importKey(
        'spki',
        base64ToArrayBuffer(memberPublicKeyBase64),
        RSA_PARAMS,
        false,
        ['encrypt']
    )

    const encrypted = await crypto.subtle.encrypt(
        { name: 'RSA-OAEP' },
        publicKey,
        new TextEncoder().encode(secret)
    )

    return arrayBufferToBase64(encrypted)
}

/**
 * Decrypt a wrapped secret using the user's own private key.
 */
export async function unwrapSharedSecret(wrappedKeyBase64) {
    const privateKey  = await getPrivateKey()
    const decrypted   = await crypto.subtle.decrypt(
        { name: 'RSA-OAEP' },
        privateKey,
        base64ToArrayBuffer(wrappedKeyBase64)
    )
    return new TextDecoder().decode(decrypted)
}

// ── API helpers ────────────────────────────────────────────────────────────

/**
 * Fetch a team member's public key from the server.
 */
export async function fetchMemberPublicKey(teamId, memberId) {
    const { data } = await apiClient.get(`/teams/${teamId}/members/${memberId}/public-key`)
    return data.public_key
}

/**
 * Share an account with multiple team members using E2EE key wrapping.
 * @param {number} teamId
 * @param {number} twofaccountId
 * @param {string} decryptedSecret - The plaintext TOTP/HOTP secret
 * @param {Array<{id: number}>} members - Team members to share with
 * @param {string} accessLevel - 'read' | 'write'
 */
export async function shareEncryptedWithTeam(teamId, twofaccountId, decryptedSecret, members, accessLevel = 'read') {
    const memberKeys = await Promise.all(
        members.map(async (member) => {
            const publicKey  = await fetchMemberPublicKey(teamId, member.id)
            if (!publicKey) throw new Error(`Member ${member.name} has no public key registered. They must enable E2EE key sharing first.`)
            const wrappedKey = await wrapSecretForMember(decryptedSecret, publicKey)
            return { member_id: member.id, wrapped_key: wrappedKey }
        })
    )

    return apiClient.post(`/teams/${teamId}/share-encrypted`, {
        twofaccount_id: twofaccountId,
        access_level:   accessLevel,
        member_keys:    memberKeys,
    })
}
