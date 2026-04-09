import { Page, Locator, APIResponse } from '@playwright/test';
import { routes } from '../fixtures/test-data.fixture';

type ConflictResolution = 'skip' | 'replace' | 'rename';

type ImportOptions = {
  password: string;
  conflictResolution?: ConflictResolution;
  importGroups?: boolean;
};

export class BackupSettingsPage {
  readonly page: Page;
  readonly exportButton: Locator;
  readonly fileInput: Locator;
  readonly activeModal: Locator;

  constructor(page: Page) {
    this.page = page;
    this.exportButton = page.locator('button.button.is-primary').filter({ hasText: 'Export Backup' });
    this.fileInput = page.locator('input.file-input');
    this.activeModal = page.locator('.modal.is-active');
  }

  async goto() {
    await this.page.goto(routes.settingsBackup);
    await this.page.waitForLoadState('networkidle');
  }

  async uploadBackupFile(contents: string, fileName = 'backup.vault') {
    await this.fileInput.setInputFiles({
      name: fileName,
      mimeType: 'application/json',
      buffer: Buffer.from(contents),
    });

    await this.activeModal.waitFor({ state: 'visible', timeout: 15000 });
  }

  async setImportPassword(password: string) {
    await this.activeModal.locator('.modal-card-body input[type="password"]').fill(password);
  }

  async setConflictResolution(mode: ConflictResolution) {
    await this.activeModal.locator(`input[type="radio"][value="${mode}"]`).check();
  }

  async setImportGroups(enabled: boolean) {
    const checkbox = this.activeModal.locator('input[type="checkbox"]').first();
    const count = await checkbox.count();

    if (!count) return;

    if (enabled) {
      await checkbox.check();
    } else {
      await checkbox.uncheck();
    }
  }

  async confirmImportAndWaitForResponse(): Promise<APIResponse> {
    const importResponsePromise = this.page.waitForResponse((response) =>
      response.url().includes('/api/v1/backups/import') && response.request().method() === 'POST'
    );

    await this.activeModal.getByRole('button', { name: 'Confirm' }).click();
    return importResponsePromise;
  }

  async importBackup(contents: string, options: ImportOptions): Promise<APIResponse> {
    await this.uploadBackupFile(contents);
    await this.setImportPassword(options.password);

    if (options.conflictResolution) {
      await this.setConflictResolution(options.conflictResolution);
    }

    if (typeof options.importGroups === 'boolean') {
      await this.setImportGroups(options.importGroups);
    }

    return this.confirmImportAndWaitForResponse();
  }
}
