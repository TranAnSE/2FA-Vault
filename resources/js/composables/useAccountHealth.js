import { ref } from 'vue'
import accountHealthService from '@/services/accountHealthService'
import { computeClientScore } from '@/services/account-health-client'

/**
 * Fetches server-side health scores and (optionally, when the vault is
 * unlocked) merges in client-side entropy/duplicate scores into a combined
 * grade. Client-side scoring is wired in by account-health-client.js
 * (Phase 3). When `decryptedAccounts` is null/empty, only server scores are
 * surfaced and the grade is marked as server-only.
 */

// Combined weighting: server metadata weighted above client secret checks.
const SERVER_WEIGHT = 0.6
const CLIENT_WEIGHT = 0.4

const scores = ref(new Map())      // Map<id, serverScore>
const summary = ref(null)
const clientScores = ref(new Map()) // Map<id, {entropy_score, duplicate_score, client_total}>
const isUnlocked = ref(false)

function combinedTotal(serverScore, clientScore) {
    if (!clientScore) return serverScore.server_total
    return Math.round(SERVER_WEIGHT * serverScore.server_total + CLIENT_WEIGHT * clientScore.client_total)
}

function gradeFromTotal(total) {
    if (total >= 90) return 'A'
    if (total >= 75) return 'B'
    if (total >= 60) return 'C'
    if (total >= 40) return 'D'
    return 'F'
}

export function useAccountHealth() {
    async function fetchScore(accountId) {
        const { data } = await accountHealthService.getScore(accountId)
        scores.value.set(accountId, data)
        return combinedView(accountId)
    }

    async function fetchSummary() {
        const { data } = await accountHealthService.getSummary()
        summary.value = data
        return data
    }

    /**
     * Merge client-side scores (set by Phase 3 after vault unlock).
     * @param {Map<number,{entropy_score:number,duplicate_score:number}>} map
     */
    function setClientScores(map) {
        clientScores.value = map
        isUnlocked.value = map.size > 0
    }

    /**
     * Compute client-side entropy/duplicate scores from decrypted accounts
     * (vault unlocked) and merge them into the combined view. Call with an
     * empty array to revert to server-only scoring when the vault locks.
     */
    function applyClientScores(decryptedAccounts) {
        setClientScores(computeClientScore(decryptedAccounts))
    }

    function combinedView(accountId) {
        const server = scores.value.get(accountId)
        if (!server) return null
        const client = clientScores.value.get(accountId)
        const total = combinedTotal(server, client)
        return {
            ...server,
            entropy_score: client?.entropy_score ?? null,
            duplicate_score: client?.duplicate_score ?? null,
            client_total: client?.client_total ?? null,
            combined_total: total,
            combined_grade: gradeFromTotal(total),
            mode: client ? 'combined' : 'server-only',
        }
    }

    function get(accountId) {
        return combinedView(accountId)
    }

    return {
        scores, summary, isUnlocked,
        fetchScore, fetchSummary, setClientScores, applyClientScores, get,
        SERVER_WEIGHT, CLIENT_WEIGHT,
    }
}
