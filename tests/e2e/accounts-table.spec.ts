import { test, expect } from './fixtures/auth.fixture';
import { routes } from './fixtures/test-data.fixture';
import { AccountCreatePage } from './pages/AccountCreatePage';

/**
 * v1.5.0 e2e: desktop table layout (upstream 1c98d9d0..5e494223, adapted).
 *
 * Covers the preference toggle, table rendering with the in-table toolbar,
 * the <1215px fallback to list, management actions on table rows and the
 * favorites-filter x drag-reorder regression (useAccountSort must translate
 * filtered-list positions onto the full items list).
 */

async function enableDesktopTableLayout(page: import('@playwright/test').Page) {
  await page.goto(routes.settingsOptions);
  // the checkradio input is visually hidden — drive its label
  const control = page.locator('label[for="useDesktopTableLayout"]');
  await control.waitFor({ state: 'visible', timeout: 15000 });
  const checkbox = page.locator('input#useDesktopTableLayout');
  if (!(await checkbox.isChecked())) {
    await control.click();
    // let the preference PUT settle before navigating away
    await page.waitForTimeout(500);
  }
}

async function disableDesktopTableLayout(page: import('@playwright/test').Page) {
  await page.goto(routes.settingsOptions);
  const control = page.locator('label[for="useDesktopTableLayout"]');
  await control.waitFor({ state: 'visible', timeout: 15000 });
  const checkbox = page.locator('input#useDesktopTableLayout');
  if (await checkbox.isChecked()) {
    await control.click();
    await page.waitForTimeout(500);
  }
}

async function createAccount(page: import('@playwright/test').Page, service: string) {
  await page.goto(routes.createAccount);
  const createPage = new AccountCreatePage(page);
  await createPage.serviceInput.waitFor({ state: 'visible', timeout: 15000 });
  await createPage.fillAccount({ service, account: `${service}@test.local`, secret: 'A4GRFTVVRBGY7UIW' });
  await createPage.submit();
  // the create POST must complete before the caller navigates away
  await page.waitForURL('**/accounts', { timeout: 15000 });
}

test.describe('Desktop table layout', () => {
  test('renders accounts as a table on desktop and falls back to a list when narrow', async ({ page, loginAsAdmin }) => {
    const service = `Table-${Date.now().toString(36)}`;
    await createAccount(page, service);

    await enableDesktopTableLayout(page);
    await page.goto(routes.accounts);
    await expect(page.getByText(service).first()).toBeVisible({ timeout: 15000 });

    // table markup + a visible in-table toolbar (New/Delete controls live in
    // the footer in table mode too)
    await expect(page.locator('table.tfa-table')).toBeVisible();
    await expect(page.locator('tr.tfa-table', { has: page.getByText(service) })).toBeVisible();
    await expect(page.locator('#table-toolbar #btnSelectAll')).toBeVisible();

    // under the desktop breakpoint the table falls back to the list span
    await page.setViewportSize({ width: 1024, height: 800 });
    await expect(page.locator('table.tfa-table')).toHaveCount(0);
    await expect(page.locator('#dv')).toBeVisible();

    // restore
    await page.setViewportSize({ width: 1280, height: 800 });
    await disableDesktopTableLayout(page);
  });

  test('selects and bulk-deletes rows in table mode', async ({ page, loginAsAdmin }) => {
    const service = `TableDel-${Date.now().toString(36)}`;
    await createAccount(page, service);

    await enableDesktopTableLayout(page);
    await page.goto(routes.accounts);
    const row = page.locator('tr.tfa-table', { has: page.getByText(service) });
    await expect(row).toBeVisible({ timeout: 15000 });

    // select the row's checkbox (selection model drives the footer actions);
    // the checkradio input is visually hidden — drive its label
    await row.locator('label').first().click();
    await expect(row.locator('input[type="checkbox"]')).toBeChecked();
    await expect(page.locator('#btnDelete')).toBeEnabled();

    // bulk delete goes through a native confirm() dialog
    page.on('dialog', dialog => dialog.accept());
    await page.locator('#btnDelete').click();

    await expect(page.locator('tr.tfa-table', { has: page.getByText(service) })).toHaveCount(0, { timeout: 15000 });
    await disableDesktopTableLayout(page);
  });

  test('drag-reorder under the favorites-only filter moves only the dragged account', async ({ page, loginAsAdmin }) => {
    // a previous test may have leaked the table preference — force list mode
    await disableDesktopTableLayout(page);

    const stamp = Date.now().toString(36);
    const first = `Drag1-${stamp}`;
    const second = `Drag2-${stamp}`;
    const third = `Drag3-${stamp}`;
    for (const name of [first, second, third]) {
      await createAccount(page, name);
    }

    await page.goto(routes.accounts);

    // favorite the first two accounts via their star buttons
    for (const name of [first, second]) {
      const row = page.locator('.tfa-container', { has: page.getByText(name) }).first();
      await expect(row).toBeVisible({ timeout: 15000 });
      await row.locator('.tfa-favorite').click();
    }

    // restrict the view to favorites only
    await page.locator('button[title="Show favorites only"]').first().click();
    await expect(page.locator('.tfa-container', { has: page.getByText(third) })).toHaveCount(0);

    // enter management mode and drag the first visible row below the second
    await page.locator('#btnManage').click();
    const handles = page.locator('.tfa-container .drag-handle');
    await expect(handles).toHaveCount(2);

    const from = await handles.nth(0).boundingBox();
    const to = await handles.nth(1).boundingBox();
    expect(from).not.toBeNull();
    expect(to).not.toBeNull();

    await page.mouse.move(from!.x + from!.width / 2, from!.y + from!.height / 2);
    await page.mouse.down();
    await page.mouse.move(to!.x + to!.width / 2, to!.y + to!.height / 2, { steps: 12 });
    await page.waitForTimeout(150);
    await page.mouse.up();

    // wait for the reorder POST to settle
    await page.waitForTimeout(1200);

    // leave management mode, clear the filter, reload: only the dragged
    // account's relative position changed
    await page.locator('#lnkExitEdit').click();
    await page.locator('button[title="Show favorites only"]').first().click();
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(
        page.locator('.tfa-container', { has: page.getByText(second) }).first()
    ).toBeVisible({ timeout: 15000 });

    const services = page.locator('.tfa-content .tfa-text');
    const order: string[] = [];
    const count = await services.count();
    for (let i = 0; i < count; i++) {
      const text = await services.nth(i).innerText();
      for (const name of [first, second, third]) {
        if (text.includes(name)) order.push(name);
      }
    }
    // [first, second] became [second, first]; third stays after both
    expect(order.slice(0, 2)).toEqual([second, first]);
  });
});
