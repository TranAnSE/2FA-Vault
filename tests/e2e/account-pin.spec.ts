import { test, expect } from './fixtures/live-app.fixture';
import { AccountCreatePage } from './pages/AccountCreatePage';

/**
 * v1.2.0 e2e: account pin toggle.
 *
 * Click the pin icon on an account -> reload -> still pinned -> unpin.
 */
test.describe('Account pin', () => {
  test('pin persists across reload, then unpins', async ({ authedPage: page, goto }) => {
    // Ensure at least one account exists to pin.
    await goto('/account/create');
    const createPage = new AccountCreatePage(page);
    await createPage.serviceInput.waitFor({ state: 'visible', timeout: 15000 });
    const service = `PinTarget-${Date.now().toString(36)}`;
    await createPage.fillAccount({ service, account: 'pin@test.local', secret: 'A4GRFTVVRBGY7UIW' });
    await createPage.submit();
    await goto('/accounts');
    await expect(page.getByText(service).first()).toBeVisible({ timeout: 15000 });

    // The pin button lives on the account row; target the one for our service.
    const accountRow = page.locator('.tfa-container', { has: page.locator(`text=${service}`) }).first();
    await expect(accountRow).toBeVisible({ timeout: 10000 });

    const pinBtn = accountRow.locator('.tfa-pin').first();
    // Before pinning the icon is grey (not warning).
    await expect(pinBtn).toBeVisible();

    // Pin it.
    await pinBtn.click();
    // Optimistic update marks the button with the warning (pinned) class.
    await expect(pinBtn).toHaveClass(/has-text-warning/, { timeout: 10000 });

    // Reload and confirm the pin persisted server-side.
    await goto('/accounts');
    await expect(page.getByText(service).first()).toBeVisible({ timeout: 15000 });
    const accountRowAfterReload = page.locator('.tfa-container', { has: page.locator(`text=${service}`) }).first();
    const pinBtnAfterReload = accountRowAfterReload.locator('.tfa-pin').first();
    await expect(pinBtnAfterReload).toHaveClass(/has-text-warning/, { timeout: 10000 });

    // Unpin.
    await pinBtnAfterReload.click();
    await expect(pinBtnAfterReload).not.toHaveClass(/has-text-warning/, { timeout: 10000 });
  });
});
