import type { Page } from '@playwright/test';

/**
 * WebAuthn PRF ceremony helpers (plan 261114-2101, phase 5).
 *
 * Chromium's CDP virtual authenticator supports the prf extension
 * (hmac-secret) when created with `hasPrf: true` — proven by the phase-1
 * spike (fixture-gen/spike-evidence/prf-spike-results.json, GATE PASS).
 * These helpers let the real product code run its whole ceremony
 * (register / PRF enroll / PRF unlock) against that virtual authenticator
 * with zero mocks: the browser performs the actual navigator.credentials
 * calls and the server verifies the real signatures.
 */

export type PrfAuthenticator = {
    /** CDP authenticator handle (WebAuthn.removeVirtualAuthenticator). */
    authenticatorId: string;
    cdp: import('@playwright/test').CDPSession;
};

/**
 * Attach a PRF-capable virtual authenticator to the page. Must run before
 * any navigator.credentials call on the page. The authenticator simulates
 * user presence + UV automatically (no prompts).
 */
export async function attachPrfAuthenticator(page: Page): Promise<PrfAuthenticator> {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: true,
            hasUserVerification: true,
            isUserVerified: true,
            automaticPresenceSimulation: true,
            hasPrf: true,
        },
    });

    return { authenticatorId, cdp };
}

/**
 * Client-side (router.push) navigation that never reloads the document.
 * A full page load clears the in-memory E2EE key and bounces to
 * /unlock-vault, so settings-page hops inside a flow use this instead.
 */
export async function spaNavigate(page: Page, path: string): Promise<void> {
    const pushed = await page.evaluate(async (p) => {
        const el = document.querySelector('#app');
        // @ts-ignore vue devtools handle
        const app = el && el.__vue_app__;
        const router = app?.config?.globalProperties?.$router;
        if (!router) return false;
        try {
            await router.push(p);
            return true;
        } catch {
            return false;
        }
    }, path).catch(() => false);

    if (!pushed) {
        throw new Error(`spaNavigate: SPA router not mounted, cannot push ${path}`);
    }

    // Let async route resolution + middleware settle onto the new route.
    await page.waitForTimeout(250);
}
