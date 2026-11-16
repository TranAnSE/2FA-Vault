/**
 * PRF vault-unlock lifecycle (plan 261114-2101, phase 5).
 *
 * One sequential, real-ceremony test against a CDP virtual authenticator
 * (hasPrf: true — capability proven by the phase-1 spike). No ceremony is
 * mocked: registration, PRF enroll, PRF unlock, master-password rotation
 * re-wrap and revocation all run the shipped product code end-to-end.
 * Run via `npm run test:e2e:prf` (--workers=1, fresh DB per run).
 *
 * The test registers its OWN user through the UI and completes E2EE setup
 * with a known master password, so the vault is genuinely paired with that
 * password (the seeded e2eEncrypted fixture pair predates a crypto-params
 * change and no longer verifies — pre-existing, see plan validation log).
 */
import { test, expect } from '@playwright/test';
import { attachPrfAuthenticator, spaNavigate } from './helpers/webauthn-ceremony';

// WebAuthn rp.id must be a valid domain AND Chromium binds it to the page
// origin's host — so this spec MUST run against http://localhost:8001 (the
// default e2e origin 127.0.0.1 is an IP → every ceremony rejects with
// "This is an invalid domain"), with the server serving the same origin:
//   E2E_ORIGIN=http://localhost:8001 npm run test:e2e:prf
test.use({ baseURL: 'http://localhost:8001' });

const MASTER_PASSWORD = 'MasterPass123!';
const ACCOUNT_PASSWORD = 'TestPassword123!';
const ROTATED_PASSWORD = 'RotatedPass456!';

async function registerUserWithVault(page) {
    const email = `prf-${Date.now().toString(36)}@test.local`;

    await page.goto('/register');
    await page.locator('#txtName').waitFor({ state: 'visible', timeout: 20000 });
    await page.locator('#txtName').fill('PRF E2E User');
    await page.locator('#emlEmail').fill(email);
    await page.locator('#pwdPassword').fill(ACCOUNT_PASSWORD);
    await page.locator('#btnRegister').click();

    // Dismiss the post-registration WebAuthn onboarding prompt if it appears.
    const maybeLater = page.locator('#btnMaybeLater');
    await Promise.race([
        maybeLater.waitFor({ state: 'visible', timeout: 15000 }),
        expect(page).toHaveURL(/\/(start|accounts|setup-encryption)/, { timeout: 20000 }),
    ]).catch(() => {});
    if (await maybeLater.isVisible().catch(() => false)) {
        await maybeLater.click();
    }

    await expect(page).toHaveURL(/\/(start|accounts|setup-encryption)/, { timeout: 20000 });

    // A fresh user lands on /start (first-account onboarding); E2EE setup is
    // reached through Settings → Encryption → "Enable encryption".
    if (!/\/setup-encryption/.test(page.url())) {
        await spaNavigate(page, '/settings/encryption');
        await page.getByRole('button', { name: /Enable Encryption/i }).click();
    }

    await expect(page).toHaveURL(/\/setup-encryption/, { timeout: 20000 });
    await page.locator('#pwdMasterpassword').waitFor({ state: 'visible', timeout: 15000 });
    await page.locator('#pwdMasterpassword').fill(MASTER_PASSWORD);
    await page.locator('#pwdMasterpassword_confirmation').fill(MASTER_PASSWORD);

    // Bulma is-checkradio input may be visually hidden — toggle via the label.
    const understood = page.locator('#understood');
    if (!(await understood.isChecked().catch(() => false))) {
        await page.locator('label[for="understood"]').first().click();
    }

    await expect(page.locator('#btnEnableEncryption')).toBeEnabled({ timeout: 10000 });
    await page.locator('#btnEnableEncryption').click();
    await expect(page).toHaveURL(/\/(accounts|start)/, { timeout: 30000 });
}

async function lockVaultFromSettings(page) {
    await spaNavigate(page, '/settings/encryption');
    await page.getByRole('button', { name: /Lock Vault/ }).click();
    await expect(page.locator('#pwdMasterpassword').first()).toBeVisible({ timeout: 15000 });
}

async function unlockWithPasskey(page) {
    await spaNavigate(page, '/unlock-vault');
    const button = page.locator('#btnPrfUnlock');
    await expect(button).toBeVisible({ timeout: 15000 });
    await button.click();
    await expect(page).toHaveURL(/\/(accounts|start)/, { timeout: 30000 });
    // A user with zero 2FA accounts lands on /start (starter middleware
    // routes empty vaults there) — either landing proves the unlock pushed
    // the user out of /unlock-vault.
}

test.describe('PRF vault unlock lifecycle', () => {
    test('enroll → lock → passkey unlock → rotate → passkey unlock → revoke', async ({ page }) => {
        // Full real-crypto lifecycle: multiple Argon2id derivations (setup,
        // rotation old+new), three WebAuthn ceremonies and the D9 re-wrap —
        // comfortably exceeds Playwright's default 30s.
        test.setTimeout(600000);

        // Log WebAuthn + PRF API traffic and any 4xx/5xx — ceremonies are
        // opaque in UI snapshots (failures surface as auto-dismissing toasts).
        page.on('response', async (response) => {
            const url = response.url();
            if (url.includes('/webauthn/') || url.includes('/encryption/prf/') || response.status() >= 400) {
                console.log('[api]', response.status(), response.request().method(), url);
            }
        });

        // ---- 1. Fresh user with a genuinely-paired vault (K already in memory)
        await registerUserWithVault(page);

        // ---- 2. Register a passkey through the real UI ceremony
        await spaNavigate(page, '/settings/webauthn');
        // Attach the PRF-capable virtual authenticator HERE — after the last
        // full-page navigation (CDP authenticators do not reliably survive
        // cross-document navigations; every step after this is SPA-only).
        await attachPrfAuthenticator(page);

        await page.getByText('Register a new device').click();
        await expect(page).toHaveURL(/\/settings\/webauthn\/.+\/edit/, { timeout: 30000 });

        // ---- 3. PRF enroll ceremony (D2: server challenge + possession proof)
        await spaNavigate(page, '/settings/webauthn');
        await expect(page.getByText('Enable vault unlock')).toBeVisible({ timeout: 30000 });
        await page.getByText('Enable vault unlock').click();
        await expect(page.getByText('Vault unlock enabled for this passkey.')).toBeVisible({ timeout: 30000 });

        // ---- 4. Lock vault and unlock via passkey
        await lockVaultFromSettings(page);
        await unlockWithPasskey(page);

        // ---- 5. Rotate the master password (D9 re-wrap runs inside)
        await spaNavigate(page, '/settings/encryption');
        // FormField ids lowercase everything after the first char
        // (useIdGenerator): 'currentPasswordForRotation' → pwdCurrentpasswordforrotation.
        await page.locator('#pwdCurrentpasswordforrotation').fill(MASTER_PASSWORD);
        await page.locator('#pwdNewpasswordforrotation').fill(ROTATED_PASSWORD);
        await page.locator('#pwdConfirmpasswordforrotation').fill(ROTATED_PASSWORD);
        await page.locator('#btnRotateMasterPassword').click();
        await expect(page.getByText('Master password changed and all secrets re-encrypted')).toBeVisible({ timeout: 120000 });

        // ---- 6. Lock + passkey unlock again — proves the D9 re-wrap
        await lockVaultFromSettings(page);
        await unlockWithPasskey(page);

        // ---- 7. Revoke the passkey; the FK cascade must retire the unlock button
        await spaNavigate(page, '/settings/webauthn');
        page.once('dialog', (dialog) => dialog.accept());
        await page.getByText('Revoke', { exact: true }).click();
        await expect(page.getByText('Enable vault unlock').or(page.getByText('Vault unlock enabled'))).toHaveCount(0, { timeout: 15000 });

        // ---- 8. Lock → unlock-vault shows no passkey button (0 enrollments)
        await lockVaultFromSettings(page);
        await spaNavigate(page, '/unlock-vault');
        await page.getByLabel('Master Password', { exact: true }).waitFor({ state: 'visible', timeout: 15000 });
        await expect(page.locator('#btnPrfUnlock')).not.toBeVisible({ timeout: 10000 });
    });
});
