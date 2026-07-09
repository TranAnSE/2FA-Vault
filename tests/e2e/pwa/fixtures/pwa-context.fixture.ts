import path from 'node:path';
import { fileURLToPath } from 'node:url';
import os from 'node:os';
import fs from 'node:fs';
import { chromium, test as base, expect, type BrowserContext, type Page } from '@playwright/test';
import { testUsers, routes, sel } from '../../fixtures/test-data.fixture';

/**
 * PWA E2E fixture.
 *
 * Service workers, the Cache Storage API, IndexedDB, and the
 * `beforeinstallprompt` event all require a *persistent* browser context
 * (a real on-disk user-data-dir) — the default Playwright `page` fixture uses
 * an incognito context that does not persist SW registrations across navigations.
 *
 * This fixture launches a persistent Chromium context, logs the encrypted
 * e2e user in via the legacy form, unlocks the vault, waits for the service
 * worker to be ready, and hands the test a `{ page, context }` pair that is
 * ready for offline / installability / cache assertions.
 *
 * `headless: false` is required: Chromium does not install or activate service
 * workers in the new headless mode unless `--headless=new` is paired with the
 * PWAsOnHeadlessShell feature, and `beforeinstallprompt` never fires in
 * headless. We mirror the extension popup fixture's approach.
 */

const E2E_BASE_URL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8001';
const MASTER_PASSWORD = 'MasterPass123!';

type PwaFixture = {
  page: Page;
  context: BrowserContext;
};

const __dirname = path.dirname(fileURLToPath(import.meta.url));

async function loginViaLegacyForm(page: Page, email: string, password: string): Promise<void> {
  await page.goto(routes.login);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.locator('#app').waitFor({ state: 'attached', timeout: 15000 });

  // The SPA may boot into WebAuthn/SSO view depending on stored preference;
  // flip to legacy form if the switch link is present.
  const legacyForm = page.locator(sel.legacyLoginForm);
  const legacyLink = page.locator(sel.switchToLegacy);
  if (!(await legacyForm.isVisible().catch(() => false))) {
    if (await legacyLink.isVisible().catch(() => false)) {
      await legacyLink.click();
      await legacyForm.waitFor({ state: 'visible', timeout: 15000 });
    }
  }

  await legacyForm.waitFor({ state: 'visible', timeout: 15000 });
  await legacyForm.locator(sel.emailInput).fill(email);
  await legacyForm.locator(sel.passwordInput).fill(password);
  await legacyForm.locator(sel.signInButton).click();

  // After login the encrypted user lands on /unlock-vault.
  await Promise.race([
    page.waitForURL('**/unlock-vault', { timeout: 15000 }),
    page.waitForURL('**/accounts', { timeout: 15000 }),
    page.waitForURL('**/start', { timeout: 15000 }),
  ]);
}

async function unlockVault(page: Page, masterPassword: string): Promise<void> {
  // If already past the gate (accounts or start), nothing to do.
  if (/\/(accounts|start)$/.test(page.url())) return;

  if (!/\/unlock-vault/.test(page.url())) {
    await page.goto(routes.unlockVault);
  }

  const passwordInput = page.getByLabel('Master Password', { exact: true });
  await passwordInput.waitFor({ state: 'visible', timeout: 15000 });
  await passwordInput.fill(masterPassword);
  await page.getByRole('button', { name: /Unlock Vault/ }).click();

  // After a correct unlock the SPA routes to /accounts, but on fresh profiles
  // it may first pass through /start. Accept either and then normalise to
  // /accounts so the downstream SW/cache assertions have a stable entry point.
  await Promise.race([
    page.waitForURL('**/accounts', { timeout: 30000 }),
    page.waitForURL('**/start', { timeout: 30000 }),
  ]);

  if (!/\/accounts$/.test(page.url())) {
    await page.goto(routes.accounts);
    await page.waitForLoadState('networkidle').catch(() => {});
  }
}

export const test = base.extend<PwaFixture>({
  context: async ({}, use) => {
    // Persistent profile under the OS temp dir so SW + caches survive navigations.
    const profileDir = fs.mkdtempSync(path.join(os.tmpdir(), '2fa-vault-pwa-'));
    const context = await chromium.launchPersistentContext(profileDir, {
      headless: false,
      args: [
        // Enable PWA / installability signals in the automated browser.
        '--enable-features=PWAsUpdatingRelatedAppsOnInstall,DesktopPWAsWithoutExtensions',
        '--disable-features=PrivacySandboxSettings4',
      ],
    });

    try {
      await use(context);
    } finally {
      await context.close().catch(() => {});
      // Best-effort cleanup; do not fail the run if Windows still holds a lock.
      fs.rm(profileDir, { recursive: true, force: true }, () => {});
    }
  },

  page: async ({ context }, use) => {
    const pages = context.pages();
    const page = pages.length > 0 ? pages[0] : await context.newPage();

    await loginViaLegacyForm(page, testUsers.encrypted.email, testUsers.encrypted.password);
    await unlockVault(page, MASTER_PASSWORD);

    // Wait for the service worker to be active before handing control to tests.
    await page.waitForFunction(
      async () => {
        if (!('serviceWorker' in navigator)) return false;
        const reg = await navigator.serviceWorker.ready;
        return Boolean(reg && reg.active);
      },
      { timeout: 30000 },
    );

    await use(page);
  },
});

export { expect, E2E_BASE_URL, MASTER_PASSWORD };
