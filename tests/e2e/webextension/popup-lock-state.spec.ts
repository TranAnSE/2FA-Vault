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

    // The vault must NOT be entered. Depending on the lock policy and PAT
    // validity, a failed unlock either stays on /unlock or escalates to
    // /unauthorized — both are acceptable as long as /accounts is NOT shown.
    await popup.waitForTimeout(1500);
    expect(popup.url()).not.toMatch(/#\/accounts/);
    expect(popup.url()).toMatch(/#\/(unlock|unauthorized|error)/);
  });

  test('@requires-webextension-build unlock form rejects empty submit and stays locked', async ({ popup }) => {
    await popup.goto(popup.url().replace(/#\/.*$/, '#/unlock'));
    await popup.waitForSelector('#frmUnlock', { timeout: 15000 });

    // Submitting an empty password must not advance into the vault.
    await popup.locator('#btnUnlock').click();
    await popup.waitForTimeout(800);
    expect(popup.url()).not.toMatch(/#\/accounts/);
    expect(popup.url()).toMatch(/#\/(unlock|unauthorized|error)/);
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
