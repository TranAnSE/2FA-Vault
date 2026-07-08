import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, test as base, expect } from '@playwright/test';
import { ensureWebExtensionEncryptedUserReady } from './bootstrap-webextension-user';
import { webExtensionTestData } from './popup-test-data.fixture';

/**
 * Browser-extension popup SETUP flow tests.
 *
 * Unlike popup-encrypted-local-otp.spec.ts (which uses the extension-popup
 * fixture that auto-completes setup before handing the page to the test
 * body), these tests deliberately exercise the *unconfigured* popup surface:
 * landing → setup form → validation → successful configuration → persistence
 * of the host URL into chrome.storage.local.
 */

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const extensionDist = path.resolve(__dirname, '../../../../2FA-Vault-WebExtension/dist/chrome-mv3');

type FreshPopupFixture = { popup: import('@playwright/test').Page; extensionId: string };

export const test = base.extend<FreshPopupFixture>({
  popup: async ({}, use) => {
    // Launch a FRESH persistent context so chrome.storage.local is empty
    // and the popup starts on the Landing route (isConfigured === false).
    const context = await chromium.launchPersistentContext('', {
      headless: false,
      args: [
        `--disable-extensions-except=${extensionDist}`,
        `--load-extension=${extensionDist}`,
      ],
    });

    try {
      let [background] = context.serviceWorkers();
      if (!background) {
        background = await context.waitForEvent('serviceworker');
      }
      const extensionId = background.url().split('/')[2];

      const popup = await context.newPage();
      await popup.goto(`chrome-extension://${extensionId}/popup.html`);

      // Should land on the Landing route when not configured.
      await popup.waitForURL(/#\/(landing|setup|purpose)/, { timeout: 15000 });

      await use({ popup, extensionId } as unknown as FreshPopupFixture);
    } finally {
      await context.close();
    }
  },
});

test.describe('WebExtension popup setup flow', () => {
  test.setTimeout(120000);

  test('@requires-webextension-build landing page offers a configure entry point when not configured', async ({ popup }) => {
    // The Landing view exposes a button to start configuration. The router
    // may auto-redirect landing → setup; accept either route.
    await popup.waitForURL(/#\/(landing|setup|purpose)/, { timeout: 15000 });

    if (/#\/landing/.test(popup.url())) {
      await popup.locator('#btnConfigureMe').click();
      await popup.waitForURL(/#\/setup/, { timeout: 10000 });
    }

    // On setup, the three-field form must be visible.
    await popup.waitForSelector('#frmExtSetup', { timeout: 15000 });
    await expect(popup.locator('#txtHosturl')).toBeVisible();
    await expect(popup.locator('#txtApitoken')).toBeVisible();
    await expect(popup.locator('#pwdExtpassword')).toBeVisible();
    await expect(popup.locator('#btnSubmitSetup')).toBeVisible();
  });

  test('@requires-webextension-build rejects an invalid host URL before submitting', async ({ popup }) => {
    if (!/#\/setup/.test(popup.url())) {
      await popup.goto(popup.url().replace(/#.*$/, '#/setup'));
      await popup.waitForSelector('#frmExtSetup', { timeout: 15000 });
    }

    // A clearly malformed host URL should not be accepted.
    await popup.locator('#txtHosturl').fill('not-a-url');
    await popup.locator('#txtApitoken').fill('some-token');
    await popup.locator('#pwdExtpassword').fill(webExtensionTestData.extensionPassword);

    // Either client-side validation disables submit, or submitting surfaces an error.
    const submitDisabled = !(await popup.locator('#btnSubmitSetup').isEnabled().catch(() => false));
    if (!submitDisabled) {
      await popup.locator('#btnSubmitSetup').click();
      // Should still be on a configuration route (no /accounts), with an error visible.
      await popup.waitForTimeout(1000);
      expect(popup.url()).toMatch(/#\/(setup|error)/);
    }
  });

  test('@requires-webextension-build completes setup and persists hostUrl into chrome.storage.local', async ({ popup }) => {
    // Provision a real PAT against the running server.
    const { pat } = await ensureWebExtensionEncryptedUserReady();

    if (!/#\/setup/.test(popup.url())) {
      await popup.goto(popup.url().replace(/#.*$/, '#/setup'));
      await popup.waitForSelector('#frmExtSetup', { timeout: 15000 });
    }

    await popup.locator('#txtHosturl').fill(webExtensionTestData.extensionHostUrl);
    await popup.locator('#txtApitoken').fill(pat);
    await popup.locator('#pwdExtpassword').fill(webExtensionTestData.extensionPassword);
    await popup.locator('#btnSubmitSetup').click();

    // Setup success routes into the app; the exact next route depends on
    // vault lock state (accounts / unlock / restrictions).
    await popup.waitForURL(/#\/(accounts|restrictions|unlock)/, { timeout: 30000 });

    // The persisted Pinia settingStore key under the "local:" prefix should
    // now carry the configured host URL.
    const stored = await popup.evaluate(async () => {
      const all = await chrome.storage.local.get(null);
      const hit = Object.entries(all).find(
        ([, value]) =>
          typeof value === 'object' && value !== null && typeof (value as any).hostUrl === 'string',
      );
      return hit ? (hit[1] as any).hostUrl : null;
    });

    expect(stored).toBe(webExtensionTestData.extensionHostUrl);
  });
});
