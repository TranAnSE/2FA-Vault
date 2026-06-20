/**
 * Client-side account health checks that require decrypted secrets.
 *
 * Runs entirely in-browser after vault unlock. The server never receives
 * plaintext secrets — entropy and duplicate detection are computed locally and
 * surfaced only as scores. Reuses the Base32 decoder pattern from
 * offline-totp.js (DRY) and the duplicate-detection shape from vaultHealth.js.
 *
 * Scoring (constants for easy tuning):
 *   entropy_score:   decoded secret bytes <10 = 0, 10-15 = 50, 16-19 = 75, >=20 = 100
 *   duplicate_score: secret unique = 100, shared by >1 account = 0
 *   client_total:    round((entropy_score + duplicate_score) / 2)
 */

const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'

/**
 * Decode a Base32 (RFC 4648) secret to its raw byte length.
 * Returns 0 for any malformed/undecodable input (never throws) so a single
 * bad secret cannot break scoring for the whole vault.
 */
export function secretByteLength(secret) {
    if (!secret || typeof secret !== 'string') return 0
    const encoded = secret.toUpperCase().replace(/=+$/, '')

    let bits = 0
    let value = 0
    let byteCount = 0

    for (let i = 0; i < encoded.length; i++) {
        const idx = BASE32_CHARS.indexOf(encoded[i])
        if (idx === -1) continue

        value = (value << 5) | idx
        bits += 5

        if (bits >= 8) {
            byteCount++
            bits -= 8
        }
    }

    return byteCount
}

export function entropyScore(secret) {
    const bytes = secretByteLength(secret)
    if (bytes < 10) return 0
    if (bytes <= 15) return 50
    if (bytes <= 19) return 75
    return 100
}

/**
 * Build a Map of duplicate secrets across accounts.
 * @param {Array<{secret?:string}>} accounts
 * @returns {Map<string, number>} secretKey -> occurrence count
 */
export function buildDuplicateMap(accounts) {
    const counts = new Map()
    for (const a of accounts) {
        if (!a?.secret) continue
        counts.set(a.secret, (counts.get(a.secret) || 0) + 1)
    }
    return counts
}

export function duplicateScore(secret, duplicateMap) {
    if (!secret) return 100
    return (duplicateMap.get(secret) || 0) > 1 ? 0 : 100
}

/**
 * Compute client-side scores for all accounts.
 * @param {Array<{id:number, secret?:string}>} accounts Decrypted accounts (vault unlocked).
 * @returns {Map<number, {entropy_score:number, duplicate_score:number, client_total:number}>}
 */
export function computeClientScore(accounts) {
    const list = Array.isArray(accounts) ? accounts : []
    const duplicateMap = buildDuplicateMap(list)
    const result = new Map()

    for (const account of list) {
        const entropy = entropyScore(account.secret)
        const duplicate = duplicateScore(account.secret, duplicateMap)
        result.set(account.id, {
            entropy_score: entropy,
            duplicate_score: duplicate,
            client_total: Math.round((entropy + duplicate) / 2),
        })
    }

    return result
}
