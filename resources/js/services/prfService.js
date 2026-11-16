/**
 * PRF vault-unlock crypto core (plan 261114-2101, phase 3).
 *
 * CRYPTO ONLY — no HTTP, no UI (prfApiService.js owns HTTP). Implements the
 * Bitwarden-style per-credential keyset (D1):
 *
 *   PRF output --HKDF--> wrapping key W --AES-GCM--> wraps RSA private key
 *   vault key K --RSA-OAEP(public)--> encapsulated next to the wrapped private key
 *
 * The server stores only these wrapped blobs; K, the PRF output and the
 * master password never leave the client. Password rotation re-wraps via the
 * public key alone — no ceremony (D9).
 */

import * as cryptoApi from '@/services/crypto'

// WebCrypto bound explicitly: a bare `crypto` identifier is NOT safe in the
// bundled app — unplugin-auto-import scans resources/js/services/** and
// registers the DEFAULT export of services/crypto.js under the name
// `crypto`, so any bare `crypto.subtle/...` reference gets rewritten into an
// import of that object (no `subtle` member → TypeError). Verified via the
// built chunks (plan 261114-2101 phase 5 e2e finding; webauthnService and
// prfUnlockSupport got the same class of breakage). globalThis.crypto
// cannot be rewritten.
const webCrypto = globalThis.crypto

// RSA-OAEP params mirroring keySharingService.js (fork convention)
const RSA_PARAMS = {
    name:           'RSA-OAEP',
    modulusLength:  2048,
    publicExponent: new Uint8Array([0x01, 0x00, 0x01]),
    hash:           'SHA-256',
}

/**
 * Reject a broken-authenticator all-zero PRF output (research §4 hardening).
 * @param {Uint8Array} prfOutputBits
 */
function rejectAllZero(prfOutputBits) {
    if (!prfOutputBits || prfOutputBits.length === 0 || prfOutputBits.every((b) => b === 0)) {
        throw new PrfError('prf_output_invalid', 'The authenticator returned an invalid PRF output')
    }
}

/**
 * Typed error so UI layers can map failure classes distinctly (phase 4):
 * - prf_output_invalid: all-zero/empty PRF output (broken authenticator)
 * - unwrap_failed: wrong PRF output or replaced blobs (distinct stale-blob signal)
 */
export class PrfError extends Error {
    constructor(kind, message) {
        super(message)
        this.name = 'PrfError'
        this.kind = kind
    }
}

/**
 * Build the enrollment keyset for one credential while the vault is unlocked.
 *
 * @param {Uint8Array} prfOutputBits - 32-byte prf results.first from the enroll ceremony
 * @param {Uint8Array} vaultKeyRaw - raw vault key (K) bytes, from cryptoStore.encryptionKeyRaw
 * @returns {Promise<{public_key: string, wrapped_private_key: string, wrapped_vault_key: string}>}
 *          blobs in the API wire format (snake_case), ready to spread into
 *          the POST encryption/prf/enroll payload
 */
export async function buildEnrollment(prfOutputBits, vaultKeyRaw) {
    rejectAllZero(prfOutputBits)

    // W = HKDF(PRF output)
    const wrappingKey = await cryptoApi.deriveWrappingKey(prfOutputBits)

    // Per-credential RSA-OAEP keypair (extractable: the private key gets wrapped)
    const keyPair = await webCrypto.subtle.generateKey(RSA_PARAMS, true, ['encrypt', 'decrypt'])

    const pkcs8 = await webCrypto.subtle.exportKey('pkcs8', keyPair.privateKey)
    const spki = await webCrypto.subtle.exportKey('spki', keyPair.publicKey)

    // wrappedPrivateKey = AES-256-GCM envelope of the PKCS#8 private key under W
    // (crypto.js envelope format: {ciphertext, iv, authTag}, JSON string)
    const wrappedPrivateKey = JSON.stringify(await cryptoApi.encryptSecret(bytesToBinaryString(pkcs8), wrappingKey))

    // wrappedVaultKey = RSA-OAEP(public, K raw)
    const wrappedVaultKey = await webCrypto.subtle.encrypt({ name: 'RSA-OAEP' }, keyPair.publicKey, vaultKeyRaw)

    return {
        // Snake_case: matches the POST enrollment wire format AND the
        // GET context enrollment objects, so callers can pass the server
        // enrollment straight to rewrapVaultKey/unlockVaultWithPrf.
        public_key: cryptoApi.bytesToBase64(new Uint8Array(spki)),
        wrapped_private_key: wrappedPrivateKey,
        wrapped_vault_key: cryptoApi.bytesToBase64(new Uint8Array(wrappedVaultKey))
    }
}

/**
 * Recover the raw vault key from an enrollment using a fresh PRF output.
 *
 * @param {Uint8Array} prfOutputBits - 32-byte prf results.first from the unlock ceremony
 * @param {{public_key?: string, wrapped_private_key: string, wrapped_vault_key: string}} enrollment
 *        a GET context enrollment object (server wire format)
 * @returns {Promise<Uint8Array>} raw vault key bytes
 * @throws {PrfError} kind 'unwrap_failed' when the private-key unwrap fails
 *         (wrong PRF output or replaced blobs)
 */
export async function unlockVaultWithPrf(prfOutputBits, enrollment) {
    rejectAllZero(prfOutputBits)

    const wrappingKey = await cryptoApi.deriveWrappingKey(prfOutputBits)

    let pkcs8Binary
    try {
        pkcs8Binary = await cryptoApi.decryptSecret(JSON.parse(enrollment.wrapped_private_key), wrappingKey)
    } catch {
        throw new PrfError('unwrap_failed', 'The wrapped private key could not be unwrapped with this passkey')
    }

    // Re-import the private key and decapsulate K — inside the same failure
    // contract: corrupted/replaced wrapped_vault_key blobs throw raw
    // OperationError/DataError here otherwise (review fix).
    let raw
    try {
        const pkcs8 = binaryStringToBytes(pkcs8Binary)
        const privateKey = await webCrypto.subtle.importKey('pkcs8', pkcs8, { name: 'RSA-OAEP', hash: 'SHA-256' }, false, ['decrypt'])

        raw = await webCrypto.subtle.decrypt({ name: 'RSA-OAEP' }, privateKey, cryptoApi.base64ToBytes(enrollment.wrapped_vault_key))
    } catch {
        throw new PrfError('unwrap_failed', 'The encapsulated vault key could not be recovered with this passkey')
    }

    return new Uint8Array(raw)
}

/**
 * Re-encapsulate a NEW vault key under an enrollment's stored public key —
 * the password-rotation re-wrap (D9). Public-key-only: no PRF ceremony.
 *
 * @param {{public_key: string, wrapped_private_key?: string}} enrollment
 *        a GET context enrollment object (server wire format)
 * @param {Uint8Array} newVaultKeyRaw - raw K_new bytes
 * @returns {Promise<string>} the new wrapped_vault_key blob (base64)
 */
export async function rewrapVaultKey(enrollment, newVaultKeyRaw) {
    const publicKey = await webCrypto.subtle.importKey('spki', cryptoApi.base64ToBytes(enrollment.public_key), { name: 'RSA-OAEP', hash: 'SHA-256' }, false, ['encrypt'])

    const wrapped = await webCrypto.subtle.encrypt({ name: 'RSA-OAEP' }, publicKey, newVaultKeyRaw)

    return cryptoApi.bytesToBase64(new Uint8Array(wrapped))
}

// ── helpers ────────────────────────────────────────────────────────────────

/**
 * ArrayBuffer → latin1 binary string (per-byte, NOT UTF-8 — TextEncoder/
 * TextDecoder would corrupt DER bytes ≥ 0x80). The AES envelope API is
 * string-oriented; latin1 strings survive its TextEncoder round trip because
 * every charCode ≤ 0xFF maps to the same byte on re-encode via charCodeAt.
 */
function bytesToBinaryString(buffer) {
    return String.fromCharCode(...new Uint8Array(buffer))
}

function binaryStringToBytes(binaryString) {
    return Uint8Array.from(binaryString, (char) => char.charCodeAt(0))
}

