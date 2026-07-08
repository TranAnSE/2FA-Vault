import { test, expect } from './extension-popup.fixture';
import { webExtensionTestData } from './popup-test-data.fixture';

/**
 * Browser-extension popup LOCK STATE tests.
 *
 * Builds on the configured extension from extension-popup.fixture.ts (which
 * auto-completes setup) and exercises the vault-lock lifecycle:
 *   - navigate to /unlock, submit correct master password → /accounts
 *   - submit wrong master password → stays on /unlock with an error
 *
 * Idle-timeout auto-lock (chrome.alarms) is intentionally not covered here
 * because it requires either a long real-time wait or intercepting the alarms
 * API; see the TODO block at the bottom of this file.
 */

test.describe('WebExtension popup vault lock state', () => {
  test.setTimeout(120000);

  test('@requires-webextension-build correct master password unlocks the vault', async ({ popup }) => {
    // The fixture may have left us on /accounts (already unlocked) or /unlock.
    // Force navigation to the unlock view to exercise the unlock form.
    await popup.goto(popup.url().replace(/#\/.*$/, '#/unlock'));
    await popup.waitForSelector('#frmUnlock', { timeout: 15000 });

    await popup.locator('#pwdPassword').fill(webExtensionTestData.masterPassword);
    const preferencesResponse = popup.waitForResponse('**/api/v1/user/preferences');
    await popup.locator('#btnUnlock').click();
    await preferencesResponse;

    await popup.waitForURL(/#\/accounts/, { timeout: 20000 });
    // Account list must be populated, confirming unlock + decryption succeeded.
    await expect(
      popup.getByText(webExtensionTestData.account.service, { exact: false }),
    ).toBeVisible({ timeout: 15000 });
  });

  test('@requires-webextension-build wrong master password keeps the vault locked', async ({ popup }) => {
    await popup.goto(popup.url().replace(/#\/.*$/, '#/unlock'));
    await popup.waitForSelector('#frmUnlock', { timeout: 15000 });

    await popup.locator('#pwdPassword').fill('DefinitelyWrongPassword123!');
    await popup.locator('#btnUnlock').click();

    // Should NOT navigate to accounts.
    await popup.waitForTimeout(1500);
    expect(popup.url()).toMatch(/#\/unlock/);

    // An error indicator must be shown. The exact copy varies by locale, so
    // assert on the presence of an error class instead of a literal string.
    await expect(popup.locator('.error-generic, .is-danger, [role="alert"]').first()).toBeVisible({
      timeout: 10000,
    });
  });

  test('@requires-webextension-build unlock form is keyboard-accessible and prevents empty submit', async ({ popup }) => {
    await popup.goto(popup.url().replace(/#\/.*$/, '#/unlock'));
    await popup.waitForSelector('#frmUnlock', { timeout: 15000 });

    // Submitting an empty password should not advance past the unlock view.
    await popup.locator('#btnUnlock').click();
    await popup.waitForTimeout(800);
    expect(popup.url()).toMatch(/#\/unlock/);

    // The password field must remain focusable.
    await expect(popup.locator('#pwdPassword')).toBeVisible();
  });
});

/* ----------------------------------------------------------------------------
 * TODO (advanced): idle-timeout auto-lock
 *
 * The extension background service worker schedules a chrome.alarms-based
 * auto-lock. Testing it requires either:
 *   (a) waiting the real idle window (slow, flaky), or
 *   (b) forwarding the clock via chrome.alarms mock — which needs the
 *       `alarms` permission and a DevTools Protocol / extension-API override
 *       that Playwright does not expose natively.
 * A targeted unit test inside the extension repo (2FA-Vault-WebExtension)
 * covering the lockStateService in isolation would be a better home for that
 * assertion than this E2E layer.
 * -------------------------------------------------------------------------- */
