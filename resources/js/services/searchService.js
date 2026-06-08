/**
 * Client-side full-text search + multi-criteria filter.
 * Used when the vault is E2EE-encrypted (accounts are already decrypted in memory).
 * The server never receives search terms or decrypted data.
 */

const PRESETS_KEY = '2fauth:filter-presets'

export function buildSearchIndex(accounts) {
    return accounts.map(account => ({
        id: account.id,
        text: [
            account.service ?? '',
            account.account ?? '',
            ...(account.tags?.map(t => t.name) ?? []),
        ].join(' ').toLowerCase(),
        otp_type:  account.otp_type,
        algorithm: account.algorithm,
        digits:    account.digits,
        group_id:  account.group_id,
        tag_ids:   account.tags?.map(t => t.id) ?? [],
        encrypted: account.encrypted,
        last_used_at: account.last_used_at,
    }))
}

/**
 * @param {Array} index - built with buildSearchIndex()
 * @param {Array} accounts - original account objects
 * @param {string} query - search text
 * @param {object} filters
 * @returns {Array} filtered account objects
 */
export function searchAccounts(index, accounts, query, filters = {}) {
    let results = index

    if (query?.trim()) {
        const terms = query.trim().toLowerCase().split(/\s+/)
        results = results.filter(item => terms.every(t => item.text.includes(t)))
    }

    if (filters.types?.length) {
        results = results.filter(i => filters.types.includes(i.otp_type))
    }
    if (filters.algorithms?.length) {
        results = results.filter(i => filters.algorithms.includes(i.algorithm))
    }
    if (filters.digits?.length) {
        results = results.filter(i => filters.digits.includes(i.digits))
    }
    if (filters.group_id) {
        results = results.filter(i => i.group_id === filters.group_id)
    }
    if (filters.tag_ids?.length) {
        if (filters.tag_mode === 'and') {
            results = results.filter(i => filters.tag_ids.every(tid => i.tag_ids.includes(tid)))
        } else {
            results = results.filter(i => filters.tag_ids.some(tid => i.tag_ids.includes(tid)))
        }
    }
    if (filters.encrypted !== undefined && filters.encrypted !== null) {
        results = results.filter(i => i.encrypted === filters.encrypted)
    }
    if (filters.last_used_from) {
        const from = new Date(filters.last_used_from).getTime()
        results = results.filter(i => i.last_used_at && new Date(i.last_used_at).getTime() >= from)
    }
    if (filters.last_used_to) {
        const to = new Date(filters.last_used_to).getTime()
        results = results.filter(i => i.last_used_at && new Date(i.last_used_at).getTime() <= to)
    }

    const ids = new Set(results.map(r => r.id))
    return accounts.filter(a => ids.has(a.id))
}

export function saveFilterPreset(name, filters) {
    const presets = loadFilterPresets()
    presets[name] = filters
    localStorage.setItem(PRESETS_KEY, JSON.stringify(presets))
}

export function loadFilterPresets() {
    try { return JSON.parse(localStorage.getItem(PRESETS_KEY) ?? '{}') } catch { return {} }
}

export function deleteFilterPreset(name) {
    const presets = loadFilterPresets()
    delete presets[name]
    localStorage.setItem(PRESETS_KEY, JSON.stringify(presets))
}
