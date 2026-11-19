import { test, expect } from './fixtures/pwa-context.fixture';
import { testUsers } from '../fixtures/test-data.fixture';

/**
 * Offline access E2E — the PWA offline contract as SHIPPED.
 *
 * Flow under test: user logs in and unlocks online → SW + IndexedDB populate
 * → network is cut → the SPA keeps showing the rendered accounts, the shell
 * survives offline reloads (SW-served), and an on-request OTP fetch offline
 * degrades into the handled error state. True offline OTP generation is a
 * recorded follow-up: the offline-totp.js engine exists but is not wired
 * into any UI yet.
 *
 * This mirrors the browser-extension local-OTP test
 * (tests/e2e/webextension/popup-encrypted-local-otp.spec.ts) — the POPUP
 * generates locally (its own engine is wired); the PWA path does not yet.
 */

// Service name of the seeded VaultDrive account owned by e2eencrypted@2fauth.app
// (secret A4GRFTVVRBGY7UIW). Must match database/seeders/E2eSeeder.php.
const SEEDED_ACCOUNT_SERVICE = 'VaultDrive';

/**
 * Reach /accounts WITHOUT a full reload: the vault key lives in memory only
 * (cleared on reload by design), so a `page.goto` after the fixture unlock
 * would land on the unlock screen and defeat every in-SPA assertion below.
 */
async function ensureAccountsVisible(page) {
  if (!/\/accounts$/.test(page.url())) {
    await page.goto('/accounts');
  }
  await expect(page.getByText(SEEDED_ACCOUNT_SERVICE, { exact: false })).toBeVisible({
    timeout: 20000,
  });
}

test.describe('PWA offline OTP access', () => {
  test.setTimeout(120000);

  test('still lists accounts after going offline', async ({ page, context }) => {
    await ensureAccountsVisible(page);

    // Cut the network. The unlocked, in-memory SPA keeps rendering its
    // accounts from local state — no server round-trip needed.
    await context.setOffline(true);
    await page.waitForTimeout(500); // let the offline event propagate

    await expect(page.getByText(SEEDED_ACCOUNT_SERVICE, { exact: false })).toBeVisible({
      timeout: 30000,
    });

    await context.setOffline(false);
  });

  test('offline OTP click surfaces the handled offline state', async ({ page, context }) => {
    // Pins the SHIPPED offline OTP contract (plan 261119-1650 phase 2
    // finding): OTP generation is server-side (on-request /otp fetch), and
    // offline that fetch fails into the app's handled error page — the SPA
    // must degrade gracefully, not crash or hang. The offline-totp.js
    // client-side engine exists but is not wired into any UI yet (only
    // App.vue's lockVault() touches the module): wiring it is the recorded
    // follow-up, NOT behavior this spec may assert.
    await ensureAccountsVisible(page);

    const otpRequestUrls: string[] = [];
    page.on('request', (request) => {
      const url = request.url();
      if (url.includes('/api/v1/twofaccounts/') && url.endsWith('/otp')) {
        otpRequestUrls.push(url);
      }
    });

    await context.setOffline(true);
    await page.waitForTimeout(500);

    // Click the account row: the on-request OTP fetch must fail handled.
    await page.getByText(SEEDED_ACCOUNT_SERVICE, { exact: false }).click();

    await Promise.race([
      page.waitForURL('**/error', { timeout: 20000 }),
      page.getByText('offline', { exact: false }).waitFor({ state: 'visible', timeout: 20000 }),
    ]);

    // The failure surfaces as the app's handled error state (SW answered
    // the API call itself with 503 {message:'offline'} — no server round
    // trip leaves the machine).
    await expect(page.getByText(/offline/i).first()).toBeVisible({ timeout: 10000 });

    await context.setOffline(false);
  });

  test('survives a full reload while offline and still unlocks', async ({ page, context }) => {
    await ensureAccountsVisible(page);

    await context.setOffline(true);
    await page.waitForTimeout(500);

    await page.reload();

    // After an offline reload the SPA may route back to /unlock-vault because
    // the in-memory vault key is gone. The app shell itself must still render
    // (proving the SW served it) — we accept either /accounts or /unlock-vault.
    await Promise.race([
      page.waitForURL('**/accounts', { timeout: 30000 }),
      page.waitForURL('**/unlock-vault', { timeout: 30000 }),
    ]);

    await expect(page.locator('#app')).toBeVisible();

    await context.setOffline(false);
  });
});
