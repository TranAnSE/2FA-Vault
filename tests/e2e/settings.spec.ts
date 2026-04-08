import { test, expect } from './fixtures/auth.fixture';
import { routes } from './fixtures/test-data.fixture';

const backupPassword = 'BackupPass123!';
const invalidBackupContents = '{ not-valid-json';
const validBackupJson = JSON.stringify({
  format: '2FA-Vault',
  version: '2.0',
  encrypted: true,
  doubleEncrypted: true,
  exportedAt: '2026-04-09T00:00:00Z',
  accounts: [
    {
      service: 'Imported Service',
      account: 'imported@example.com',
      secret: 'JBSWY3DPEHPK3PXP',
      encrypted: false,
      algorithm: 'sha1',
      digits: 6,
      period: 30,
      otp_type: 'totp',
    },
  ],
  groups: [
    { id: 1, name: 'Imported Group', order: 0 },
  ],
});

test.describe('Settings', () => {
  test.beforeEach(async ({ loginAsAdmin }) => {
    // Authenticated as admin
  });

  test('P2: Settings options page loads', async ({ page }) => {
    await page.goto(routes.settingsOptions);
    await page.waitForLoadState('networkidle');

    await expect(page.locator('.label').first()).toBeVisible({ timeout: 10000 });
  });

  test('P1: Backup page shows export controls for encrypted user', async ({ page, loginAsEncrypted }) => {
    await page.goto(routes.settingsBackup);
    await page.waitForLoadState('networkidle');

    await expect(page.getByRole('heading', { name: 'Export Backup' })).toBeVisible();
    await expect(page.locator('button.button.is-primary').filter({ hasText: 'Export Backup' })).toBeVisible();
    await expect(page.getByText('End-to-End Encryption must be enabled to create encrypted backups.')).toHaveCount(0);
  });

  test('P1: Backup page warns when encryption is disabled', async ({ page, loginAsUser }) => {
    await page.goto(routes.settingsBackup);
    await page.waitForLoadState('networkidle');

    await expect(page.getByText('End-to-End Encryption must be enabled to create encrypted backups.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Enable Encryption' })).toBeVisible();
  });

  test('P1: Export backup validates password and succeeds', async ({ page, loginAsEncrypted }) => {
    await page.goto(routes.settingsBackup);
    await page.waitForLoadState('networkidle');

    await page.locator('button.button.is-primary').filter({ hasText: 'Export Backup' }).click();
    await expect(page.getByText('Export Encrypted Backup')).toBeVisible();

    await page.getByRole('button', { name: 'Confirm' }).click();
    await expect(page.getByText('Please enter the backup password')).toBeVisible();

    await page.locator('.modal.is-active .modal-card-body input[type="password"]').fill(backupPassword);

    const exportResponsePromise = page.waitForResponse((response) =>
      response.url().includes('/api/v1/backups/export') && response.request().method() === 'POST'
    );

    await page.locator('.modal.is-active').getByRole('button', { name: 'Confirm' }).click();

    const exportResponse = await exportResponsePromise;
    expect(exportResponse.ok()).toBeTruthy();
    const exportBody = await exportResponse.json();
    expect(exportBody.accounts_count).toBeGreaterThanOrEqual(0);

    await expect(page.locator('.modal.is-active')).toHaveCount(0, { timeout: 15000 });
  });

  test('P1: Import backup validates file and shows preview before import', async ({ page, loginAsEncrypted }) => {
    await page.goto(routes.settingsBackup);
    await page.waitForLoadState('networkidle');

    await page.locator('input.file-input').setInputFiles({
      name: 'valid-backup.json',
      mimeType: 'application/json',
      buffer: Buffer.from(validBackupJson),
    });

    const activeModal = page.locator('.modal.is-active')
    await expect(activeModal).toBeVisible({ timeout: 15000 });
    await expect(activeModal.getByText('Backup Preview')).toBeVisible({ timeout: 15000 });
    await expect(activeModal.getByText(/Format:/)).toBeVisible();
    await expect(activeModal.getByText(/Accounts:/)).toBeVisible();
    await expect(activeModal.getByText(/Groups:/)).toBeVisible();

    await page.locator('.modal.is-active .modal-card-body input[type="password"]').fill(backupPassword);

    const importResponsePromise = page.waitForResponse((response) =>
      response.url().includes('/api/v1/backups/import') && response.request().method() === 'POST'
    );

    await page.locator('.modal.is-active').getByRole('button', { name: 'Confirm' }).click();

    const importResponse = await importResponsePromise;
    expect(importResponse.ok()).toBeTruthy();
    const importBody = await importResponse.json();
    expect(importBody.imported_count).toBe(1);

    await expect(page.locator('.modal.is-active')).toHaveCount(0, { timeout: 15000 });
  });

  test('P1: Import backup rejects invalid file', async ({ page, loginAsEncrypted }) => {
    await page.goto(routes.settingsBackup);
    await page.waitForLoadState('networkidle');

    await page.locator('input.file-input').setInputFiles({
      name: 'invalid-backup.json',
      mimeType: 'application/json',
      buffer: Buffer.from(invalidBackupContents),
    });

    await expect(page.locator('.modal.is-active')).toBeVisible({ timeout: 15000 });
    await page.locator('.modal.is-active .modal-card-body input[type="password"]').fill(backupPassword);

    const importResponsePromise = page.waitForResponse((response) =>
      response.url().includes('/api/v1/backups/import') && response.request().method() === 'POST'
    );

    await page.locator('.modal.is-active').getByRole('button', { name: 'Confirm' }).click();

    const importResponse = await importResponsePromise;
    expect(importResponse.status()).toBe(422);
    const importBody = await importResponse.json();
    expect(importBody.message).toMatch(/Invalid backup file|Failed to import backup/);

    await expect(page.locator('.modal.is-active')).toBeVisible();
  });
});
