import path from 'node:path';
import { fileURLToPath } from 'node:url';
import os from 'node:os';
import fs from 'node:fs';
import { chromium, test as base, expect, type BrowserContext, type Page } from '@playwright/test';
import { testUsers, routes, sel } from '../../fixtures/test-data.fixture';
import { SetupEncryptionPage } from '../../pages/SetupEncryptionPage';

/**
 * PWA E2E fixtures.
 *
 * Service workers, the Cache Storage API, IndexedDB, and the
 * `beforeinstallprompt` event all require a *persistent* browser context
 * (a real on-disk user-data-dir); the default Playwright `page` fixture uses
 * an incognito context that does not persist SW registrations across
 * navigations.
 *
 * Two fixtures are exported:
 *
 *  - `test` (default): logs in as the plain `e2eUser`, waits for the SW to be
 *    active, and hands the page over. Use this for service-worker / manifest /
 *    installability assertions that do not depend on an encrypted vault.
 *
 *  - `encryptedTest`: logs in as `e2eUser`, then runs the full E2EE setup flow
 *    (setup-encryption page → master password → acknowledge → submit) so the
 *    vault is unlocked with a known master password. Use this for the offline
 *    OTP access tests that need to generate codes locally.
 *
 * Why not the seeded `e2eencrypted` user? Its factory state writes a fixed
 * `encryption_test_value`/`encryption_salt` pair but no master password is
 * recorded that can reproduce that test value, so the vault cannot be
 * unlocked. Setting up E2EE fresh against the plain user is the only path
 * that yields a known master password AND an unlocked vault.
 *
 * `headless: false` is required: Chromium does not install/activate service
 * workers in the new headless shell, and `beforeinstallprompt` never fires in
 * headless. We mirror the extension popup fixture's approach.
 */

const E2E_BASE_URL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8001';
const MASTER_PASSWORD = 'MasterPass123!';

type PwaFixture = {
  page: Page;
  context: BrowserContext;
};

async function launchPersistentContext(): Promise<BrowserContext> {
  const profileDir = fs.mkdtempSync(path.join(os.tmpdir(), '2fa-vault-pwa-'));
  const context = await chromium.launchPersistentContext(profileDir, {
    headless: false,
    args: [
      // Enable PWA / installability signals in the automated browser.
      '--enable-features=PWAsUpdatingRelatedAppsOnInstall,DesktopPWAsWithoutExtensions',
      '--disable-features=PrivacySandboxSettings4',
    ],
  });
  // Stash the profile dir on the context so the fixture teardown can clean up.
  (context as any).__profileDir = profileDir;
  return context;
}

async function loginViaLegacyForm(page: Page, email: string, password: string): Promise<void> {
  await page.goto(routes.login);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.locator('#app').waitFor({ state: 'attached', timeout: 15000 });

  // The SPA may boot into WebAuthn/SSO view depending on stored preference;
  // flip to the legacy form if the switch link is present.
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

  // After login the unencrypted user lands on /accounts or /start.
  await Promise.race([
    page.waitForURL('**/accounts', { timeout: 20000 }),
    page.waitForURL('**/start', { timeout: 20000 }),
    page.waitForURL('**/setup-encryption', { timeout: 20000 }),
    page.waitForURL('**/unlock-vault', { timeout: 20000 }),
  ]);
}

/** Wait for the app's service worker to reach the active state. */
async function waitForServiceWorker(page: Page): Promise<void> {
  await page.waitForFunction(
    async () => {
      if (!('serviceWorker' in navigator)) return false;
      const reg = await navigator.serviceWorker.ready;
      return Boolean(reg && reg.active);
    },
    { timeout: 30000 },
  );
}

/**
 * Default fixture: log in as the plain unencrypted user. The vault has no
 * E2EE, so accounts are stored server-side and the SW/manifest/installability
 * assertions work without any unlock step.
 */
export const test = base.extend<PwaFixture>({
  context: async ({}, use) => {
    const context = await launchPersistentContext();
    try {
      await use(context);
    } finally {
      const profileDir = (context as any).__profileDir as string | undefined;
      await context.close().catch(() => {});
      if (profileDir) fs.rm(profileDir, { recursive: true, force: true }, () => {});
    }
  },

  page: async ({ context }, use) => {
    const pages = context.pages();
    const page = pages.length > 0 ? pages[0] : await context.newPage();

    await loginViaLegacyForm(page, testUsers.user.email, testUsers.user.password);

    // If the app routed to /start, push to /accounts so tests have a stable
    // entry point. /start is an onboarding screen, not the vault.
    if (!/\/accounts$/.test(page.url())) {
      await page.goto(routes.accounts);
      await page.waitForLoadState('networkidle').catch(() => {});
    }

    await waitForServiceWorker(page);
    await use(page);
  },
});

/**
 * Encrypted-vault fixture: same persistent context, but after login run the
 * full E2EE setup flow so the vault is encrypted + unlocked with the known
 * master password. Use for offline OTP access tests.
 */
export const encryptedTest = base.extend<PwaFixture>({
  context: async ({}, use) => {
    const context = await launchPersistentContext();
    try {
      await use(context);
    } finally {
      const profileDir = (context as any).__profileDir as string | undefined;
      await context.close().catch(() => {});
      if (profileDir) fs.rm(profileDir, { recursive: true, force: true }, () => {});
    }
  },

  page: async ({ context }, use) => {
    const pages = context.pages();
    const page = pages.length > 0 ? pages[0] : await context.newPage();

    await loginViaLegacyForm(page, testUsers.user.email, testUsers.user.password);

    // The fresh user has no E2EE, so set it up via the setup-encryption page.
    const setupPage = new SetupEncryptionPage(page);
    await setupPage.goto();
    await setupPage.fillMasterPassword(MASTER_PASSWORD);
    await setupPage.acknowledgeRisk();
    await setupPage.submit();

    // After E2EE setup the SPA lands on /accounts with the vault unlocked.
    await Promise.race([
      page.waitForURL('**/accounts', { timeout: 30000 }),
      page.waitForURL('**/start', { timeout: 30000 }),
    ]);
    if (!/\/accounts$/.test(page.url())) {
      await page.goto(routes.accounts);
      await page.waitForLoadState('networkidle').catch(() => {});
    }

    await waitForServiceWorker(page);
    await use(page);
  },
});

export { expect, E2E_BASE_URL, MASTER_PASSWORD };
