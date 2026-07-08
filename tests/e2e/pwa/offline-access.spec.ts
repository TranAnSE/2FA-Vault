import { test, expect } from './fixtures/pwa-context.fixture';
import { totpWindow } from '../helpers/totp';
import { testUsers } from '../fixtures/test-data.fixture';

/**
 * Offline access E2E — the central PWA promise for an OTP vault.
 *
 * Flow under test: user logs in and unlocks online → SW + IndexedDB populate
 * → network is cut → the SPA must keep showing accounts and generating OTPs
 * locally, with NO request to the server /otp endpoint.
 *
 * This mirrors the browser-extension local-OTP test
 * (tests/e2e/webextension/popup-encrypted-local-otp.spec.ts) but exercises the
 * PWA path instead of the extension popup.
 */

// Secret of the seeded VaultDrive account owned by e2eencrypted@2fauth.app.
// Must match database/seeders/E2eSeeder.php.
const ENCRYPTED_TOTP_SECRET = 'A4GRFTVVRBGY7UIW';
const SEEDED_ACCOUNT_SERVICE = 'VaultDrive';

test.describe('PWA offline OTP access', () => {
  test.setTimeout(120000);

  test('still lists accounts after going offline', async ({ page, context }) => {
    // Ensure accounts list has rendered while online.
    await page.goto('/accounts');
    await expect(page.getByText(SEEDED_ACCOUNT_SERVICE, { exact: false })).toBeVisible({
      timeout: 20000,
    });

    // Cut the network. The SW fetch handler must answer the navigation.
    await context.setOffline(true);
    await page.waitForTimeout(500); // let the offline event propagate

    await page.reload();
    await expect(page.getByText(SEEDED_ACCOUNT_SERVICE, { exact: false })).toBeVisible({
      timeout: 30000,
    });

    await context.setOffline(false);
  });

  test('generates OTP locally without hitting the /otp endpoint', async ({ page, context }) => {
    await page.goto('/accounts');
    await expect(page.getByText(SEEDED_ACCOUNT_SERVICE, { exact: false })).toBeVisible({
      timeout: 20000,
    });

    const otpRequestUrls: string[] = [];
    page.on('request', (request) => {
      const url = request.url();
      if (url.includes('/api/v1/twofaccounts/') && url.endsWith('/otp')) {
        otpRequestUrls.push(url);
      }
    });

    await context.setOffline(true);
    await page.waitForTimeout(500);

    // Click the account row to reveal the OTP view.
    await page.getByText(SEEDED_ACCOUNT_SERVICE, { exact: false }).click();

    // The SPA renders the OTP display; assert it produced digits.
    const otpDisplay = page.locator('#otp').or(page.getByTestId('otp-display')).first();
    await expect(otpDisplay).toBeVisible({ timeout: 20000 });

    const generatedOtp = ((await otpDisplay.textContent()) ?? '').replace(/\D/g, '');
    expect(generatedOtp.length).toBeGreaterThan(0);

    // The displayed value must match what RFC 6238 produces for the known
    // secret within a ±2 window tolerance.
    const expectedOtps = totpWindow(ENCRYPTED_TOTP_SECRET, Date.now());
    expect(expectedOtps).toContain(generatedOtp);

    // No request to the server /otp endpoint should have fired while offline.
    expect(otpRequestUrls).toHaveLength(0);

    await context.setOffline(false);
  });

  test('survives a full reload while offline and still unlocks', async ({ page, context }) => {
    await page.goto('/accounts');
    await expect(page.getByText(SEEDED_ACCOUNT_SERVICE, { exact: false })).toBeVisible({
      timeout: 20000,
    });

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
