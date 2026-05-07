import { expect, Locator, Page } from '@playwright/test';
import { routes } from '../fixtures/test-data.fixture';

export class SetupEncryptionPage {
  readonly page: Page;
  readonly passwordInputs: Locator;
  readonly checkbox: Locator;
  readonly masterPasswordInput: Locator;
  readonly confirmPasswordInput: Locator;
  readonly understoodCheckbox: Locator;
  readonly submitButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.passwordInputs = page.locator('input[type="password"]');
    this.checkbox = page.locator('input[type="checkbox"]');
    this.masterPasswordInput = page.locator('#pwdMasterpassword');
    this.confirmPasswordInput = page.locator('#pwdMasterpassword_confirmation');
    this.understoodCheckbox = page.locator('input[type="checkbox"][name="understood"]');
    this.submitButton = page.locator('button[type="submit"]');
  }

  async goto() {
    await this.page.goto(routes.setupEncryption);
    await this.page.waitForLoadState('networkidle');
  }

  async fillMasterPassword(password: string) {
    await this.masterPasswordInput.fill(password);
    await this.confirmPasswordInput.fill(password);
  }

  async acknowledgeRisk() {
    await this.understoodCheckbox.check();
    await expect(this.understoodCheckbox).toBeChecked();
    await expect(this.submitButton).toBeEnabled();
  }

  async submit() {
    await this.submitButton.click();
  }
}
