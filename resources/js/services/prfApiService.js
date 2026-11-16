/**
 * PRF vault-unlock HTTP client (plan 261114-2101, phase 3).
 *
 * HTTP ONLY — no crypto (prfService.js owns crypto). Endpoints per D7:
 * all five are E2EE-enabled-guarded + gated by manage-webauthn-credentials
 * server-side; context doubles as the unlock flow's single fetch.
 *
 * Every call opts out of the global axios error handler (`returnError`,
 * phase-5 e2e finding): the guards return 400 (E2EE off) / 403 (SSO-only) /
 * 404 (foreign credential) that the UI degrades on purpose — a non-blocking
 * probe must never trigger the full-page error view.
 */

import { httpClientFactory } from '@/services/httpClientFactory'

const apiClient = httpClientFactory('api')

export default {
    /**
     * PRF salt + E2EE fields + enrollments (unlock flow's single fetch).
     * 400 when E2EE is off, 403 on SSO-only instances; the caller decides
     * how to degrade (phase 4 swallows mount-time errors).
     */
    getPrfContext(config = {}) {
        return apiClient.get('/encryption/prf/context', { returnError: true, ...config })
    },

    /**
     * Server-generated assertion options scoped to one credential (D2
     * possession-proof ceremony). Single-use, TTL-gated challenge.
     */
    prfEnrollOptions(credentialId, config = {}) {
        return apiClient.post('/encryption/prf/enroll/options', { credential_id: credentialId }, { returnError: true, ...config })
    },

    /**
     * Store the wrapped keyset after the server verifies the assertion.
     */
    prfEnroll(payload, config = {}) {
        return apiClient.post('/encryption/prf/enroll', payload, { returnError: true, ...config })
    },

    /**
     * Rotation re-wrap: re-encapsulate the vault key under stored public
     * keys (no ceremony). 409 on stale encryption_salt_hash.
     */
    prfRewrap(payload, config = {}) {
        return apiClient.post('/encryption/prf/rewrap', payload, { returnError: true, ...config })
    },

    /**
     * Remove one enrollment (own credentials only; foreign → 404).
     */
    prfDisable(credentialId, config = {}) {
        return apiClient.delete(`/encryption/prf/${encodeURIComponent(credentialId)}`, { returnError: true, ...config })
    }
}
