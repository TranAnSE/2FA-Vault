import { test, expect } from './fixtures/auth.fixture';
import { routes } from './fixtures/test-data.fixture';

/**
 * Backup snapshots smoke (v1.4.0): create → list → merge restore through the
 * wizard → replace typed-confirmation gate → delete. The replace restore is
 * intentionally never executed — the gate itself is what's under test here
 * (the real replace semantics are covered server-side by BackupRestoreTest).
 */
test.describe('Backup snapshots', () => {
  test('snapshot create, merge restore round-trip, replace confirm gate, delete', async ({ page, loginAsAdmin }) => {
    // loginAsAdmin performs the login during fixture setup.
    void loginAsAdmin;
    await page.goto(routes.settingsBackup);
    await page.waitForLoadState('networkidle');

    const panel = page.getByTestId('snapshots-panel');
    await expect(panel).toBeVisible({ timeout: 15000 });

    // ---- Create ----
    await page.getByTestId('snapshot-label-input').fill('e2e smoke snapshot');
    await page.getByTestId('snapshot-create').click();
    const row = page.getByTestId('snapshot-row').first();
    await expect(row).toBeVisible({ timeout: 15000 });
    await expect(row).toContainText('e2e smoke snapshot');
    await expect(page.getByTestId('snapshot-source-manual').first()).toBeVisible();

    // ---- Restore wizard: merge ----
    await page.getByTestId('snapshot-restore').first().click();
    const wizard = page.getByTestId('restore-wizard');
    await expect(wizard).toBeVisible();

    await page.getByTestId('wizard-mode-merge').check();
    await page.getByTestId('wizard-next').click();

    const diff = page.getByTestId('wizard-diff');
    await expect(diff).toBeVisible({ timeout: 15000 });
    await page.getByTestId('wizard-continue').click();

    await page.getByTestId('wizard-restore-btn').click();
    await expect(page.getByTestId('wizard-result')).toBeVisible({ timeout: 15000 });
    await page.getByTestId('wizard-close').click();
    await expect(wizard).not.toBeVisible();

    // ---- Replace: typed confirmation gate (never executed) ----
    await page.getByTestId('snapshot-restore').first().click();
    await expect(wizard).toBeVisible();

    await page.getByTestId('wizard-mode-replace').check();
    await page.getByTestId('wizard-next').click();
    await expect(diff).toBeVisible({ timeout: 15000 });
    await page.getByTestId('wizard-continue').click();

    const confirmInput = page.getByTestId('wizard-confirm-input');
    await expect(confirmInput).toBeVisible();

    const restoreButton = page.getByTestId('wizard-restore-btn');
    await expect(restoreButton).toBeDisabled();

    await confirmInput.fill('restore');
    await expect(restoreButton).toBeDisabled();

    await confirmInput.fill('RESTORE');
    await expect(restoreButton).toBeEnabled();

    // Leave the vault untouched — back out instead of executing.
    await wizard.locator('.modal-card-head .delete').click();
    await expect(wizard).not.toBeVisible();

    // ---- Delete ----
    await page.getByTestId('snapshot-delete').first().click();
    await page.getByTestId('snapshot-delete-confirm').click();
    await expect(page.getByTestId('snapshots-empty')).toBeVisible({ timeout: 15000 });
  });
});
