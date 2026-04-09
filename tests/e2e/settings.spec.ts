import { test, expect } from './fixtures/auth.fixture';
import { routes } from './fixtures/test-data.fixture';
import { BackupSettingsPage } from './pages/BackupSettingsPage';

const backupPassword = 'BackupPass123!';
const invalidBackupContents = '{ not-valid-json';

function buildVaultBackup(params?: {
  service?: string;
  account?: string;
  secret?: string;
  groupName?: string;
}): string {
  const service = params?.service ?? 'Imported Service';
  const account = params?.account ?? 'imported@example.com';
  const secret = params?.secret ?? 'JBSWY3DPEHPK3PXP';
  const groupName = params?.groupName ?? 'Imported Group';

  return JSON.stringify({
    format: '2FA-Vault',
    version: '2.0',
    encrypted: true,
    double_encrypted: true,
    exported_at: '2026-04-09T00:00:00Z',
    accounts: [
      {
        service,
        account,
        secret,
        encrypted: false,
        algorithm: 'sha1',
        digits: 6,
        period: 30,
        otp_type: 'totp',
        group_id: 1,
      },
    ],
    groups: [
      { id: 1, name: groupName, order: 0 },
    ],
  });
}

test.describe('Settings', () => {
  test('P2: Settings options page loads', async ({ page, loginAsAdmin }) => {
    await page.goto(routes.settingsOptions);
    await page.waitForLoadState('networkidle');

    await expect(page.locator('.label').first()).toBeVisible({ timeout: 10000 });
  });

  test('P1: Backup page warns when encryption is disabled', async ({ page, loginAsUser }) => {
    const backupPage = new BackupSettingsPage(page);
    await backupPage.goto();

    await expect(page.getByText('End-to-End Encryption must be enabled to create encrypted backups.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Enable Encryption' })).toBeVisible();
  });

  test('P1: Locked encrypted user is route-gated to unlock before backup page', async ({ page, loginAsLockedEncrypted }) => {
    await expect(page).toHaveURL(/\/(unlock-vault|start)/);

    await page.goto(routes.settingsBackup);
    await expect(page).toHaveURL(/\/unlock-vault/);
  });

  test('P1: Import backup validates file and shows preview before import', async ({ page, loginAsAdmin }) => {
    const backupPage = new BackupSettingsPage(page);
    await backupPage.goto();

    await backupPage.uploadBackupFile(buildVaultBackup(), 'valid-backup.vault');

    await expect(backupPage.activeModal.getByText('Backup Preview')).toBeVisible({ timeout: 15000 });
    await expect(backupPage.activeModal.getByText(/Format:/)).toBeVisible();
    await expect(backupPage.activeModal.getByText(/Accounts:/)).toBeVisible();
    await expect(backupPage.activeModal.getByText(/Groups:/)).toBeVisible();

    await backupPage.setImportPassword(backupPassword);
    const importResponse = await backupPage.confirmImportAndWaitForResponse();

    expect(importResponse.ok()).toBeTruthy();
    const importBody = await importResponse.json();
    expect(importBody.imported_count).toBe(1);

    await expect(backupPage.activeModal).toHaveCount(0, { timeout: 15000 });
  });

  test('P1: Import backup rejects invalid file', async ({ page, loginAsAdmin }) => {
    const backupPage = new BackupSettingsPage(page);
    await backupPage.goto();

    await backupPage.uploadBackupFile(invalidBackupContents, 'invalid-backup.vault');
    await backupPage.setImportPassword(backupPassword);

    const importResponse = await backupPage.confirmImportAndWaitForResponse();

    expect(importResponse.status()).toBe(422);
    const importBody = await importResponse.json();
    expect(importBody.message).toMatch(/Invalid backup file|Failed to import backup/);

    await expect(backupPage.activeModal).toBeVisible();
  });

  test('P1: Import backup with skip keeps existing duplicate and reports skipped count', async ({ page, loginAsAdmin }) => {
    const backupPage = new BackupSettingsPage(page);
    await backupPage.goto();

    const duplicatePayload = buildVaultBackup({
      service: 'Google',
      account: 'user@example.com',
      secret: 'KRSXG5DSNFXGOIDM',
      groupName: 'Skip Duplicate Group',
    });

    const response = await backupPage.importBackup(duplicatePayload, {
      password: backupPassword,
      conflictResolution: 'skip',
      importGroups: true,
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.conflict_resolution).toBe('skip');
    expect(body.imported_count).toBe(0);
    expect(body.skipped_count).toBe(1);
  });

  test('P1: Import backup with replace overwrites existing duplicate', async ({ page, loginAsAdmin }) => {
    const backupPage = new BackupSettingsPage(page);
    await backupPage.goto();

    const duplicatePayload = buildVaultBackup({
      service: 'Google',
      account: 'user@example.com',
      secret: 'AAAAAAAAAAAAAAAA',
      groupName: 'Replace Duplicate Group',
    });

    const response = await backupPage.importBackup(duplicatePayload, {
      password: backupPassword,
      conflictResolution: 'replace',
      importGroups: true,
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.conflict_resolution).toBe('replace');
    expect(body.imported_count).toBe(1);
    expect(body.skipped_count).toBe(0);
  });

  test('P1: Import backup with rename creates additional account', async ({ page, loginAsAdmin }) => {
    const backupPage = new BackupSettingsPage(page);
    await backupPage.goto();

    const duplicatePayload = buildVaultBackup({
      service: 'Google',
      account: 'user@example.com',
      secret: 'BBBBBBBBBBBBBBBB',
      groupName: 'Rename Duplicate Group',
    });

    const response = await backupPage.importBackup(duplicatePayload, {
      password: backupPassword,
      conflictResolution: 'rename',
      importGroups: true,
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.conflict_resolution).toBe('rename');
    expect(body.imported_count).toBe(1);
    expect(body.skipped_count).toBe(0);
  });

  test('P1: Import backup can skip group import mapping', async ({ page, loginAsAdmin }) => {
    const backupPage = new BackupSettingsPage(page);
    await backupPage.goto();

    const groupName = `NoGroupImport-${Date.now()}`;
    const payload = buildVaultBackup({
      service: 'GrouplessImport',
      account: 'nogroup@example.com',
      secret: 'CCCCCCCCCCCCCCCC',
      groupName,
    });

    const response = await backupPage.importBackup(payload, {
      password: backupPassword,
      conflictResolution: 'skip',
      importGroups: false,
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.imported_count).toBe(1);

    await page.goto(routes.groups);
    await page.waitForLoadState('networkidle');
    await expect(page.getByText(groupName)).toHaveCount(0);
  });
});
