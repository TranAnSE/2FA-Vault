import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, test as base, expect, type Page, type BrowserContext } from '@playwright/test';
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
 *
 * Each test launches a FRESH persistent context so chrome.storage.local is
 * empty and the popup starts on the Landing route (isConfigured === false).
 */

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const extensionDist = path.resolve(__dirname, '../../../../2FA-Vault-WebExtension/dist/chrome-mv3');

type FreshPopupFixture = { popup: Page; extensionId: string };

/**
 * Launch a fresh persistent Chromium context with the extension loaded and
 * return the popup Page plus the extension id. The caller MUST close the
 * context (typically in a finally block) to avoid leaking browser processes.
 */
async function openFreshPopup(): Promise<{ popup: Page; extensionId: string; context: BrowserContext }> {
  const context = await chromium.launchPersistentContext('', {
    headless: false,
    args: [
      `--disable-extensions-except=${extensionDist}`,
      `--load-extension=${extensionDist}`,
    ],
  });

  let [background] = context.serviceWorkers();
  if (!background) {
    background = await context.waitForEvent('serviceworker');
  }
  const extensionId = background.url().split('/')[2];

  const popup = await context.newPage();
  await popup.goto(`chrome-extension://${extensionId}/popup.html`);
  await popup.waitForURL(/#\/(landing|setup|purpose)/, { timeout: 15000 });

  return { popup, extensionId, context };
}

// Fixture: each test gets its own fresh popup + extensionId. The context is
// torn down automatically when the fixture scope ends.
export const test = base.extend<FreshPopupFixture>({
  popup: async ({}, use) => {
    const { popup, context } = await openFreshPopup();
    try {
      await use(popup);
    } finally {
      await context.close().catch(() => {});
    }
  },

  extensionId: async ({ popup }, use) => {
    // The popup URL is chrome-extension://<id>/popup.html#... — extract the id
    // from it rather than launching a second context.
    const match = popup.url().match(/chrome-extension:\/\/([^/]+)\//);
    await use(match ? match[1] : '');
  },
});

test.describe('WebExtension popup setup flow', () => {
  test.setTimeout(120000);

  test('@requires-webextension-build landing page offers a configure entry point when not configured', async ({ popup }) => {
    // The router may auto-redirect landing → setup; accept either route.
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
    // Navigate to the setup form regardless of current route.
    if (!/#\/setup/.test(popup.url())) {
      await popup.goto(`${popup.url().split('#')[0]}#/setup`);
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
      await popup.goto(`${popup.url().split('#')[0]}#/setup`);
      await popup.waitForSelector('#frmExtSetup', { timeout: 15000 });
    }

    await popup.locator('#txtHosturl').fill(webExtensionTestData.extensionHostUrl);
    await popup.locator('#txtApitoken').fill(pat);
    await popup.locator('#pwdExtpassword').fill(webExtensionTestData.extensionPassword);
    await popup.locator('#btnSubmitSetup').click();

    // Setup success routes into the app; the exact next route depends on
    // vault lock state (accounts / unlock / restrictions).
    await popup.waitForURL(/#\/(accounts|restrictions|unlock)/, { timeout: 30000 });

    // The Pinia persisted-state plugin serialises each store as a JSON STRING
    // under its own key (e.g. "settings" -> "{\"hostUrl\":\"...\",...}"). Parse
    // every stringified value and look for the hostUrl field anywhere in it.
    const stored = await popup.evaluate(async (expectedHost) => {
      const all = await chrome.storage.local.get(null);
      for (const [key, value] of Object.entries(all)) {
        // Direct object shape.
        if (value && typeof value === 'object' && typeof value.hostUrl === 'string') {
          return { key, hostUrl: value.hostUrl };
        }
        // Serialised JSON shape (Pinia persisted-state default).
        if (typeof value === 'string') {
          try {
            const parsed = JSON.parse(value);
            if (parsed && typeof parsed === 'object' && typeof parsed.hostUrl === 'string') {
              return { key, hostUrl: parsed.hostUrl };
            }
          } catch {
            // not JSON, skip
          }
        }
      }
      return { key: null, hostUrl: null, dump: all };
    }, webExtensionTestData.extensionHostUrl);

    if (stored.hostUrl !== webExtensionTestData.extensionHostUrl) {
      throw new Error(
        `hostUrl not found in chrome.storage.local. storage dump=${JSON.stringify(stored.dump ?? stored, null, 2)}`,
      );
    }

    expect(stored.hostUrl).toBe(webExtensionTestData.extensionHostUrl);
  });
});
