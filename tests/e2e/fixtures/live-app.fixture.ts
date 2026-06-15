import { test as base, expect, type Page } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

/**
 * Fixture for the v1.2.0 e2e specs that run against the ALREADY-RUNNING Docker
 * app at http://localhost:8089 (no webServer auto-start, no seeded DB).
 *
 * SHARED-USER MODEL
 *   The `/user` registration route is throttled (5 requests / 60s), so every
 *   spec cannot register its own user. Instead a single test user is created
 *   lazily (and cached to disk) by the first spec; every spec then logs in as
 *   that user and unlocks its E2EE vault. The cached user survives across
 *   re-runs within the throttle window.
 *
 * ASSET-URL WORKAROUND
 *   The Docker container was built with ASSET_URL=http://127.0.0.1:8088, so the
 *   served HTML references JS/CSS on port 8088 even though the container now
 *   serves on 8089. Rewriting cross-origin module-script URLs via
 *   `route.continue({url})` still fails (CORS / ERR_FAILED on module scripts),
 *   so we rewrite the HTML *document* itself to strip the broken origin and
 *   make asset URLs relative. The browser then fetches them same-origin from
 *   the page's own host (localhost:8089), where they exist at the identical
 *   path. No Docker restart, no committed-config edit.
 */

const __dirname = path.dirname(fileURLToPath(import.meta.url));
// fixtures/ -> e2e/ -> tests/ -> project root
const CREDS_FILE = path.resolve(__dirname, '../../../.e2e-live-user.json');

const PASSWORD = 'TestPassword123!';
const MASTER_PASSWORD = 'MasterPass123!';
const BASE_URL = process.env.E2E_BASE_URL || 'http://localhost:8089';

/** Resolve a path against the live app base URL (absolute URLs pass through). */
function abs(url: string): string {
  if (/^https?:\/\//i.test(url)) return url;
  return BASE_URL.replace(/\/$/, '') + (url.startsWith('/') ? url : '/' + url);
}

export function uniqueEmail(prefix = 'e2e'): string {
  const rand = Math.random().toString(36).slice(2, 8);
  const stamp = Date.now().toString(36);
  return `${prefix}+${stamp}${rand}@test.local`;
}

async function installAssetRewrite(page: Page): Promise<void> {
  await page.route('**/*', async (route) => {
    const req = route.request();
    const url = req.url();
    const resourceType = req.resourceType();

    // The served HTML references assets on the broken port 8088. We must NOT
    // re-fetch the document (route.fetch) ourselves — that bypasses the
    // browser cookie jar and breaks CSRF on the subsequent login POST (422).
    // Instead let the document load normally from 8089, and for every
    // asset/manifest sub-request that points at 8088, fulfill it by fetching
    // the same path from the working host 8089. This keeps everything
    // same-origin and CSRF-valid.
    if (url.includes('127.0.0.1:8088')) {
      const fixedUrl = url.split('127.0.0.1:8088').join('localhost:8089');
      try {
        const response = await route.fetch({ url: fixedUrl });
        const body = await response.body();
        await route.fulfill({
          status: response.status(),
          headers: response.headers(),
          body,
        });
      } catch {
        await route.abort();
      }
      return;
    }

    await route.continue();
  });
}

/**
 * Wait for the SPA to settle on one of the post-auth routes. Uses a URL poll
 * rather than `page.waitForURL` because the served HTML still emits preload
 * hints to the broken port 8088 that can keep the document `load` event
 * pending, which makes `waitForURL` (which waits for `load`) hang.
 */
async function waitForAuthRoute(page: Page, timeout = 25000): Promise<void> {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    const url = page.url();
    if (/\/(accounts|unlock-vault|setup-encryption|start)(\b|\/|$)/.test(url)) return;
    await page.waitForTimeout(250);
  }
  throw new Error(`Timed out waiting for an auth route; current URL=${page.url()}`);
}

async function login(page: Page, email: string, password: string): Promise<void> {
  // `domcontentloaded` (not the default `load`) because the served HTML still
  // emits <link rel=preload> hints to the broken port 8088, which can keep the
  // `load` event pending and stall subsequent calls.
  await page.goto(abs('/login'), { waitUntil: 'domcontentloaded' });
  await page.locator('#app').waitFor({ state: 'attached', timeout: 20000 });
  const legacyForm = page.locator('#frmLegacyLogin');
  await legacyForm.waitFor({ state: 'visible', timeout: 20000 });
  await legacyForm.locator('#emlEmail').fill(email);
  await legacyForm.locator('#pwdPassword').fill(password);
  await legacyForm.locator('#btnSignIn').click();

  await waitForAuthRoute(page);
}

async function completeEncryptionSetup(page: Page, masterPassword: string): Promise<void> {
  await page.locator('#pwdMasterpassword').waitFor({ state: 'visible', timeout: 15000 });
  await page.locator('#pwdMasterpassword').fill(masterPassword);
  await page.locator('#pwdMasterpassword_confirmation').fill(masterPassword);
  // The "I understand" checkbox is a Bulma is-checkradio input; .check() can
  // fail if the native input is visually hidden. Toggle via its bound state
  // (click the label) and verify it landed checked.
  const understood = page.locator('#understood');
  const isChecked = await understood.isChecked().catch(() => false);
  if (!isChecked) {
    await page.locator('label[for="understood"]').first().click().catch(async () => {
      await understood.check({ force: true }).catch(async () => {
        await understood.evaluate((el: HTMLInputElement) => { el.click(); });
      });
    });
  }

  const enableBtn = page.locator('#btnEnableEncryption');
  await expect(enableBtn).toBeEnabled({ timeout: 10000 });
  await enableBtn.click();

  await waitForAuthRoute(page);
}

async function unlockVault(page: Page, masterPassword: string): Promise<void> {
  // If already routed past unlock, nothing to do.
  if (!/\/unlock-vault/.test(page.url())) return;

  await page.locator('#pwdMasterpassword').waitFor({ state: 'visible', timeout: 15000 });
  await page.locator('#pwdMasterpassword').fill(masterPassword);
  await page.locator('#btnUnlockVault').click();

  // waitForAuthRoute() treats /unlock-vault as a valid terminal route (it is
  // listed so setup-vs-unlock routing resolves), which means it returns
  // immediately while the SPA is still on /unlock-vault waiting for the async
  // unlock API to redirect to /accounts. That leaves every spec running against
  // a locked vault. After clicking unlock we must instead wait for the URL to
  // LEAVE /unlock-vault.
  const deadline = Date.now() + 25000;
  while (Date.now() < deadline) {
    if (!/\/unlock-vault/.test(page.url())) return;
    await page.waitForTimeout(250);
  }
  throw new Error(`Unlock did not redirect away from /unlock-vault; current URL=${page.url()}`);
}

async function registerNewUser(page: Page): Promise<{ email: string; password: string }> {
  const email = uniqueEmail('v12');
  await page.goto(abs('/register'), { waitUntil: 'domcontentloaded' });
  await page.locator('#app').waitFor({ state: 'attached', timeout: 20000 });
  await page.locator('#btnRegister').waitFor({ state: 'visible', timeout: 20000 });

  await page.locator('#txtName').fill('E2E v1.2');
  await page.locator('#emlEmail').fill(email);
  await page.locator('#pwdPassword').fill(PASSWORD);
  await page.locator('#btnRegister').click();

  // Post-registration the app either routes to a known page or shows a WebAuthn
  // "Maybe Later" prompt. Dismiss the prompt if present, then wait for routing.
  const maybeLater = page.locator('#btnMaybeLater');
  await Promise.race([
    waitForAuthRoute(page),
    maybeLater.waitFor({ state: 'visible', timeout: 20000 }),
  ]).catch(() => {});

  if (await maybeLater.isVisible().catch(() => false)) {
    await maybeLater.click().catch(() => {});
    await waitForAuthRoute(page).catch(() => {});
  }

  if (/\/setup-encryption$/.test(page.url())) {
    await completeEncryptionSetup(page, MASTER_PASSWORD);
  }

  return { email, password: PASSWORD };
}

/**
 * Obtain (and cache) the shared test-user credentials. The first caller in a
 * run registers the user and writes the creds to disk; subsequent callers and
 * later runs reuse them, keeping us well under the registration throttle.
 */
async function getSharedUser(page: Page): Promise<{ email: string; password: string }> {
  if (fs.existsSync(CREDS_FILE)) {
    try {
      const creds = JSON.parse(fs.readFileSync(CREDS_FILE, 'utf8'));
      if (creds?.email && creds?.password) return creds;
    } catch {
      // fall through to registration
    }
  }

  const creds = await registerNewUser(page);
  fs.writeFileSync(CREDS_FILE, JSON.stringify(creds, null, 2));
  return creds;
}

type LiveAppFixtures = {
  /**
   * A page authenticated as the shared test user with the E2EE vault unlocked,
   * sitting on /accounts. Use this for any flow that needs a logged-in session.
   */
  authedPage: Page;
  /**
   * Navigation helper that uses `domcontentloaded` (the served HTML emits
   * preload hints to the broken port 8088 which can stall the `load` event).
   * Prefer this over `page.goto` in specs.
   */
  goto: (url: string) => Promise<void>;
  /** The shared test user credentials. */
  testUser: { email: string; password: string };
};

export const test = base.extend<LiveAppFixtures>({
  authedPage: async ({ page }, use) => {
    await installAssetRewrite(page);
    const creds = await getSharedUser(page);

    // If getSharedUser just registered, we are already authed on /accounts.
    // Otherwise log the shared user in. After login the app may route to:
    //   /accounts            (E2EE already set up + unlocked)
    //   /unlock-vault        (E2EE set up, vault locked this session)
    //   /setup-encryption    (E2EE not yet configured)
    if (!/\/accounts$/.test(page.url())) {
      await login(page, creds.email, creds.password);
    }

    if (/\/setup-encryption/.test(page.url())) {
      await completeEncryptionSetup(page, MASTER_PASSWORD);
    } else {
      await unlockVault(page, MASTER_PASSWORD);
    }

    await use(page);
  },
  goto: async ({ page }, use) => {
    await use(async (url: string) => {
      const target = abs(url);
      // vue-router.push wants a path, not a full origin URL.
      const path = target.replace(/^https?:\/\/[^/]+/i, '') || '/';
      // The E2EE decryption key lives only in Pinia (in-memory) and is cleared
      // by every full page reload. So a hard navigation to any
      // encryptionGate-protected route bounces back to /unlock-vault. To keep
      // the vault unlocked across navigations, prefer client-side routing
      // (router.push) which never reloads the document. Fall back to a full
      // page.goto only when the SPA router is not yet mounted (first load).
      const pushed = await page.evaluate(async (p) => {
        const el = document.querySelector('#app');
        // @ts-ignore
        const app = el && el.__vue_app__;
        const router = app && app.config && app.config.globalProperties && app.config.globalProperties.$router;
        if (!router) return false;
        try {
          await router.push(p);
          return true;
        } catch {
          return false;
        }
      }, path).catch(() => false);

      if (!pushed) {
        // SPA not mounted yet (first navigation): full load, then unlock if the
        // gate bounced us to /unlock-vault.
        await page.goto(target, { waitUntil: 'domcontentloaded' });
        if (/\/unlock-vault/.test(page.url())) {
          await unlockVault(page, MASTER_PASSWORD);
        }
      } else {
        // router.push fires async route resolution + middleware. Give the SPA
        // a tick to settle onto the new route.
        await page.waitForTimeout(150);
      }
    });
  },
  testUser: async ({}, use) => {
    const creds = fs.existsSync(CREDS_FILE)
      ? JSON.parse(fs.readFileSync(CREDS_FILE, 'utf8'))
      : { email: '(created at runtime)', password: PASSWORD };
    await use(creds);
  },
});

export { expect, PASSWORD, MASTER_PASSWORD };
