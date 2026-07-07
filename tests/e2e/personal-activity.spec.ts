import { test, expect } from './fixtures/live-app.fixture';

/**
 * v1.2.0 e2e: personal activity log.
 *
 * Create an account -> Settings > Activity -> account_created entry visible ->
 * Clear All -> activity empty.
 */
test.describe('Personal activity', () => {
  test('account_created appears and can be cleared', async ({ authedPage: page, goto }) => {
    // --- Create an account so an activity entry is produced ---
    const service = `ActivityTarget-${Date.now().toString(36)}`;
    await goto('/account/create');
    await page.locator('#txtService').waitFor({ state: 'visible', timeout: 15000 });
    await page.locator('#txtService').fill(service);
    await page.locator('#txtAccount').fill('activity@test.local');
    await page.getByRole('radio', { name: 'TOTP' }).click();
    await page.locator('#txtSecret').waitFor({ state: 'visible', timeout: 5000 });
    await page.locator('#txtSecret').fill('A4GRFTVVRBGY7UIW');
    await page.locator('#btnCreate').click();
    // createAccount() posts asynchronously and then calls router.push({ name:
    // 'accounts' }). If we navigate before that push fires, it overrides our
    // goto target and we land back on /accounts. Wait for the SPA to actually
    // reach /accounts (proving the create flow finished) before navigating on.
    await expect(page).toHaveURL(/\/accounts/, { timeout: 15000 });

    // --- Settings > Activity ---
    await goto('/settings/activity');
    // Activity table heading.
    await expect(page.getByRole('heading', { name: /activity/i }).first()).toBeVisible({ timeout: 15000 });

    // The account_created entry should be present in the table.
    await expect(page.locator('table').first()).toBeVisible({ timeout: 10000 });
    await expect(page.getByText('account_created').first()).toBeVisible({ timeout: 15000 });

    // --- Clear All ---
    // The Clear All button is only enabled when entries exist.
    const clearAllBtn = page.getByRole('button', { name: /clear all/i }).first();
    await expect(clearAllBtn).toBeEnabled({ timeout: 10000 });

    // Confirm dialog appears (window.confirm). Auto-accept it.
    page.once('dialog', (d) => d.accept().catch(() => {}));
    await clearAllBtn.click();

    // After clearing, the empty-state notification shows.
    await expect(page.locator('.notification', { hasText: /no activity|nothing/i }).first()).toBeVisible({ timeout: 15000 });
    // And the account_created entry is gone.
    await expect(page.getByText('account_created')).toHaveCount(0);
  });
});
