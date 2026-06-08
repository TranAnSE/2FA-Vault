/**
 * Client-side vault health analysis.
 * All calculations run in-browser — no secrets are sent to the server.
 */

const UNUSED_THRESHOLD_DAYS = 90

function calculateEntropy(secret) {
    if (!secret || typeof secret !== 'string') return 0
    // Base32 alphabet: each char encodes 5 bits
    return secret.replace(/=+$/, '').length * 5
}

function rateSecret(account) {
    const entropy = calculateEntropy(account.secret)
    let score = 0
    score += Math.min(entropy / 128, 1) * 40            // entropy: max 40pts at 128+ bits
    score += (account.algorithm || 'sha1').toLowerCase() === 'sha1' ? 10 : 25  // algorithm
    score += (account.digits ?? 6) >= 8 ? 25 : 15      // digits
    return Math.round(Math.min(score, 100))
}

function findDuplicates(accounts) {
    const secretMap = new Map()
    accounts.forEach(account => {
        if (!account.secret) return
        const key = account.secret
        if (!secretMap.has(key)) secretMap.set(key, [])
        secretMap.get(key).push(account)
    })
    return [...secretMap.values()].filter(group => group.length > 1)
}

function findUnused(accounts) {
    const threshold = Date.now() - UNUSED_THRESHOLD_DAYS * 86_400_000
    return accounts.filter(a => {
        if (!a.last_used_at) return true
        return new Date(a.last_used_at).getTime() < threshold
    })
}

function analyzeSecretStrength(accounts) {
    return accounts.map(account => ({
        account,
        entropy: calculateEntropy(account.secret),
        rating: rateSecret(account),
    }))
}

function findIncomplete(accounts) {
    return accounts.filter(a => !a.icon || !a.service)
}

function findUngrouped(accounts) {
    return accounts.filter(a => !a.group_id)
}

function penaltyScore(count, total) {
    if (total === 0) return 100
    return Math.round(((total - count) / total) * 100)
}

export function calculateHealthReport(accounts) {
    if (!accounts?.length) {
        return {
            overall: 100,
            totalAccounts: 0,
            duplicates: [],
            unused: [],
            weakSecrets: [],
            incomplete: [],
            ungrouped: [],
        }
    }

    const duplicateGroups = findDuplicates(accounts)
    const duplicateAccounts = duplicateGroups.flat()
    const unused = findUnused(accounts)
    const strengthAnalysis = analyzeSecretStrength(accounts)
    const weakSecrets = strengthAnalysis.filter(s => s.rating < 50)
    const incomplete = findIncomplete(accounts)
    const ungrouped = findUngrouped(accounts)

    const total = accounts.length
    const duplicateScore = penaltyScore(duplicateAccounts.length, total)
    const unusedScore    = penaltyScore(unused.length, total)
    const weakScore      = penaltyScore(weakSecrets.length, total)
    const incompleteScore = penaltyScore(incomplete.length, total)
    const ungroupedScore  = penaltyScore(ungrouped.length, total)

    const overall = Math.round(
        duplicateScore  * 0.25 +
        unusedScore     * 0.20 +
        weakScore       * 0.30 +
        incompleteScore * 0.15 +
        ungroupedScore  * 0.10
    )

    return {
        overall,
        totalAccounts: total,
        duplicates: duplicateGroups,
        unused,
        weakSecrets,
        incomplete,
        ungrouped,
        scores: {
            duplicates:  duplicateScore,
            unused:      unusedScore,
            weakSecrets: weakScore,
            incomplete:  incompleteScore,
            ungrouped:   ungroupedScore,
        },
    }
}

export function exportHealthReport(report, accounts) {
    const data = {
        generatedAt: new Date().toISOString(),
        vaultStats: {
            totalAccounts: report.totalAccounts,
            healthScore: report.overall,
        },
        findings: {
            duplicateGroups:  report.duplicates.length,
            unusedAccounts:   report.unused.length,
            weakSecrets:      report.weakSecrets.length,
            incompleteData:   report.incomplete.length,
            ungroupedAccounts: report.ungrouped.length,
        },
        // Never include actual secrets
        unusedAccountNames: report.unused.map(a => ({ id: a.id, service: a.service, account: a.account })),
        incompleteAccounts: report.incomplete.map(a => ({ id: a.id, service: a.service, account: a.account, missingIcon: !a.icon, missingService: !a.service })),
    }

    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `vault-health-report-${new Date().toISOString().slice(0, 10)}.json`
    link.click()
    URL.revokeObjectURL(url)
}
