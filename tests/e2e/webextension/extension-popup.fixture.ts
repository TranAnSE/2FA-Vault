import path from 'path';
import { fileURLToPath } from 'url';
import { chromium, test as base, expect, Page } from '@playwright/test';
import { ensureWebExtensionEncryptedUserReady } from './bootstrap-webextension-user';
import { webExtensionTestData } from './popup-test-data.fixture';

type PopupFixture = {
  popup: Page;
};

const fixtureDir = path.dirname(fileURLToPath(import.meta.url));
const extensionDist = path.resolve(fixtureDir, '../../../../2FA-Vault-WebExtension/dist/chrome-mv3');

export const test = base.extend<PopupFixture>({
  popup: async ({}, use) => {
    console.log('[popup-fixture] bootstrap user start');
    const { pat } = await ensureWebExtensionEncryptedUserReady();
    console.log('[popup-fixture] bootstrap user done');

    console.log('[popup-fixture] launch context start');
    const context = await chromium.launchPersistentContext('', {
      headless: false,
      args: [
        `--disable-extensions-except=${extensionDist}`,
        `--load-extension=${extensionDist}`,
      ],
    });

    try {
      console.log('[popup-fixture] launch context done');
      let [background] = context.serviceWorkers();
      if (!background) {
        console.log('[popup-fixture] waiting for serviceworker');
        background = await context.waitForEvent('serviceworker');
      }
      console.log(`[popup-fixture] serviceworker ready ${background.url()}`);

      const extensionId = background.url().split('/')[2];
      console.log(`[popup-fixture] extension id ${extensionId}`);
      const popup = await context.newPage();
      console.log('[popup-fixture] popup page created');
      let popupRuntimeError: string | null = null;
      const consoleMessages: string[] = [];

      popup.on('pageerror', error => {
        popupRuntimeError = error.stack || error.message;
      });
      popup.on('console', message => {
        consoleMessages.push(`[${message.type()}] ${message.text()}`);
      });

      const failWithContext = async (step: string, error: unknown) => {
        const url = popup.url();
        const html = await popup.locator('#popup').evaluate(node => node.innerHTML).catch(() => '<popup-unavailable>');
        const runtimeError = popupRuntimeError ? `\npageerror=${popupRuntimeError}` : '';
        const consoleDump = consoleMessages.length > 0 ? `\nconsole=\n${consoleMessages.join('\n')}` : '';
        throw new Error(`${step} failed at ${url}${runtimeError}${consoleDump}\npopupHtml=${html.slice(0, 4000)}\noriginalError=${error instanceof Error ? error.message : String(error)}`);
      };

      try {
        console.log('[popup-fixture] goto setup start');
        await popup.goto(`chrome-extension://${extensionId}/popup.html#/setup`);
        console.log(`[popup-fixture] goto setup done ${popup.url()}`);
        await popup.waitForFunction(() => Boolean(document.querySelector('#frmExtSetup')) || document.querySelectorAll('#popup *').length === 0, null, { timeout: 15000 });
        console.log('[popup-fixture] initial render condition met');
        if (popupRuntimeError) {
          throw new Error(`Popup runtime error before setup render: ${popupRuntimeError}`);
        }
        await popup.waitForSelector('#frmExtSetup', { timeout: 15000 });
        console.log('[popup-fixture] setup form visible');
        const formHtml = await popup.locator('#frmExtSetup').evaluate(node => node.innerHTML);
        console.log(`[popup-fixture] setup form html ${formHtml.slice(0, 3000)}`);
        console.log(`[popup-fixture] #txtHosturl count ${await popup.locator('#txtHosturl').count()}`);
        console.log(`[popup-fixture] #txtApitoken count ${await popup.locator('#txtApitoken').count()}`);
        console.log(`[popup-fixture] #pwdExtpassword count ${await popup.locator('#pwdExtpassword').count()}`);
        await popup.locator('#txtHosturl').fill(webExtensionTestData.extensionHostUrl);
        await popup.locator('#txtApitoken').fill(pat);
        await popup.locator('#pwdExtpassword').fill(webExtensionTestData.extensionPassword);
        console.log('[popup-fixture] setup form filled');
        await popup.locator('#btnSubmitSetup').click();
        console.log('[popup-fixture] setup submitted');
        await popup.waitForURL(/#\/(accounts|restrictions|unlock)/, { timeout: 20000 });
        console.log(`[popup-fixture] post-setup url ${popup.url()}`);

        if (/restrictions$/.test(popup.url())) {
          await popup.goto(`chrome-extension://${extensionId}/popup.html#/accounts`);
          console.log('[popup-fixture] redirected from restrictions to accounts');
          await popup.waitForURL(/#\/(accounts|unlock)/, { timeout: 15000 });
          console.log(`[popup-fixture] after restrictions fallback ${popup.url()}`);
        }

        console.log('[popup-fixture] setup flow ready for test body');
      } catch (error) {
        await failWithContext('Popup setup flow', error);
      }

      await use(popup);
    } finally {
      await context.close();
    }
  },
});

export { expect };
