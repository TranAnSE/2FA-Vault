import { createHmac } from 'node:crypto';
import { test, expect } from './extension-popup.fixture';
import { webExtensionTestData } from './popup-test-data.fixture';

const encryptedTotpSecret = 'A4GRFTVVRBGY7UIW';

function base32ToBuffer(encoded: string): Buffer {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const bytes: number[] = [];
  let bits = 0;
  let value = 0;

  for (const char of encoded.toUpperCase().replace(/=+$/g, '').replace(/\s+/g, '')) {
    const index = alphabet.indexOf(char);
    if (index === -1) {
      continue;
    }

    value = (value << 5) | index;
    bits += 5;

    if (bits >= 8) {
      bytes.push((value >>> (bits - 8)) & 0xff);
      bits -= 8;
    }
  }

  return Buffer.from(bytes);
}

function totpAt(timestamp: number): string {
  const counter = Math.floor(Math.floor(timestamp / 1000) / 30);
  const counterBuffer = Buffer.alloc(8);
  counterBuffer.writeBigUInt64BE(BigInt(counter));

  const hmac = createHmac('sha1', base32ToBuffer(encryptedTotpSecret)).update(counterBuffer).digest();
  const offset = hmac[hmac.length - 1] & 0x0f;
  const binary = (
    ((hmac[offset] & 0x7f) << 24) |
    ((hmac[offset + 1] & 0xff) << 16) |
    ((hmac[offset + 2] & 0xff) << 8) |
    (hmac[offset + 3] & 0xff)
  );

  return String(binary % 1_000_000).padStart(6, '0');
}

test.describe('WebExtension popup encrypted local OTP flow', () => {
  test.setTimeout(120000);
  test('@requires-webextension-build unlocks encrypted vault, lists accounts, and keeps OTP generation local', async ({ popup }) => {
    const localOtpRequestUrls: string[] = [];

    await popup.route('**/api/v1/user/preferences', async route => {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify([
          { key: 'formatPassword', value: true, locked: true },
          { key: 'formatPasswordBy', value: 3, locked: true },
          { key: 'getOtpOnRequest', value: true, locked: true },
          { key: 'showNextOtp', value: false, locked: true },
          { key: 'showOtpAsDot', value: false, locked: true },
          { key: 'revealDottedOTP', value: false, locked: true },
        ]),
      });
    });

    popup.on('request', request => {
      const url = request.url();
      if (url.includes('/api/v1/twofaccounts/') && url.endsWith('/otp')) {
        localOtpRequestUrls.push(url);
      }
    });

    const unlockConsoleMessages: string[] = [];
    popup.on('console', message => {
      unlockConsoleMessages.push(`[${message.type()}] ${message.text()}`);
    });

    await popup.goto(popup.url().replace(/#\/accounts$/, '#/unlock'));
    await popup.waitForSelector('#frmUnlock', { timeout: 15000 });
    await popup.locator('#pwdPassword').fill(webExtensionTestData.masterPassword);
    const preferencesResponse = popup.waitForResponse('**/api/v1/user/preferences');
    await popup.locator('#btnUnlock').click();
    await preferencesResponse;
    await popup.waitForTimeout(500);

    try {
      await popup.waitForURL(/#\/accounts/, { timeout: 20000 });
    } catch (error) {
      const url = popup.url();
      const html = await popup.locator('#popup').evaluate(node => node.innerHTML).catch(() => '<popup-unavailable>');
      throw new Error(`Unlock flow failed at ${url}\nconsole=\n${unlockConsoleMessages.join('\n')}\npopupHtml=${html.slice(0, 4000)}\noriginalError=${error instanceof Error ? error.message : String(error)}`);
    }

    await expect(popup.getByText(webExtensionTestData.account.service, { exact: false })).toBeVisible({ timeout: 15000 });
    await expect(popup.getByText(webExtensionTestData.account.account, { exact: false })).toBeVisible({ timeout: 15000 });

    await popup.getByText(webExtensionTestData.account.service, { exact: false }).click();

    const otpDisplay = popup.locator('#otp');
    await expect(otpDisplay).toBeVisible({ timeout: 15000 });
    const generatedOtp = ((await otpDisplay.textContent()) ?? '').replace(/\D/g, '');
    if (!generatedOtp) {
      const html = await popup.locator('#popup').evaluate(node => node.innerHTML).catch(() => '<popup-unavailable>');
      throw new Error(`OTP display rendered without digits.\nconsole=\n${unlockConsoleMessages.join('\n')}\npopupHtml=${html.slice(0, 5000)}`);
    }
    const assertionTime = Date.now();
    const expectedOtps = [
      totpAt(assertionTime - 60000),
      totpAt(assertionTime - 30000),
      totpAt(assertionTime),
      totpAt(assertionTime + 30000),
      totpAt(assertionTime + 60000),
    ];

    expect(expectedOtps).toContain(generatedOtp);
    expect(localOtpRequestUrls.length).toBe(0);
  });

  test('@requires-webextension-build reports local HOTP counter sync failures', async ({ popup }) => {
    await popup.route('**/api/v1/user/preferences', async route => {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify([
          { key: 'formatPassword', value: true, locked: true },
          { key: 'formatPasswordBy', value: 3, locked: true },
          { key: 'getOtpOnRequest', value: true, locked: true },
          { key: 'showNextOtp', value: false, locked: true },
          { key: 'showOtpAsDot', value: false, locked: true },
          { key: 'revealDottedOTP', value: false, locked: true },
        ]),
      });
    });

    await popup.route('**/api/v1/twofaccounts/*/counter', async route => {
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'The counter must be greater than the current HOTP counter.',
          errors: {
            counter: ['The counter must be greater than the current HOTP counter.'],
          },
        }),
      });
    });

    await popup.goto(popup.url().replace(/#\/accounts$/, '#/unlock'));
    await popup.waitForSelector('#frmUnlock', { timeout: 15000 });
    await popup.locator('#pwdPassword').fill(webExtensionTestData.masterPassword);
    const preferencesResponse = popup.waitForResponse('**/api/v1/user/preferences');
    await popup.locator('#btnUnlock').click();
    await preferencesResponse;
    await popup.waitForURL(/#\/accounts/, { timeout: 20000 });
    await expect(popup.getByText(webExtensionTestData.hotpAccount.service, { exact: false })).toBeVisible({ timeout: 15000 });

    await popup.getByText(webExtensionTestData.hotpAccount.service, { exact: false }).click({
      modifiers: ['ControlOrMeta'],
    });

    await popup.waitForURL(/#\/error/, { timeout: 15000 });
    await expect(popup.locator('.error-generic')).toBeVisible({ timeout: 15000 });
    await expect(popup.getByText('The counter must be greater than the current HOTP counter.')).toBeVisible({ timeout: 15000 });
  });
});
