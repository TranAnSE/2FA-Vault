import { test, expect } from './fixtures/live-app.fixture';

/**
 * v1.2.0 e2e: account notes.
 *
 * Create account with notes -> open the account for edit -> notes visible ->
 * edit notes -> save -> updated.
 *
 * Notes are only editable in the CreateUpdate form (there is no separate
 * "detail" view), so "view detail -> notes visible" means reopening the account
 * in the edit form and reading the populated notes textarea.
 */
test.describe('Account notes', () => {
  test('notes are saved and can be edited', async ({ authedPage: page, goto }) => {
    const service = `NotesTarget-${Date.now().toString(36)}`;
    const initialNotes = 'Initial notes line one.';
    const updatedNotes = 'Updated notes — completely new text.';

    // --- Create account with notes ---
    await goto('/account/create');
    await page.locator('#txtService').waitFor({ state: 'visible', timeout: 15000 });
    await page.locator('#txtService').fill(service);
    await page.locator('#txtAccount').fill('notes@test.local');
    // Select TOTP so the secret field appears.
    await page.getByRole('radio', { name: 'TOTP' }).click();
    await page.locator('#txtSecret').waitFor({ state: 'visible', timeout: 5000 });
    await page.locator('#txtSecret').fill('A4GRFTVVRBGY7UIW');

    // Fill the notes textarea. The "Notes" <label> has no `for` and the
    // <textarea> has no id, so getByLabel cannot associate them — target the
    // textarea inside the field whose label reads "Notes".
    const notesTextarea = page.locator('.field', { has: page.locator('.label', { hasText: /^Notes$/ }) }).locator('textarea').first();
    await notesTextarea.waitFor({ state: 'visible', timeout: 10000 });
    await notesTextarea.fill(initialNotes);

    await page.locator('#btnCreate').click();
    // createAccount() posts asynchronously and then calls router.push({ name:
    // 'accounts' }). If we goto() before that push lands it overrides our
    // target, so wait for the SPA to reach /accounts first.
    await expect(page).toHaveURL(/\/accounts/, { timeout: 15000 });
    await expect(page.getByText(service).first()).toBeVisible({ timeout: 15000 });

    // --- View detail: open the account for editing ---
    // Enter management mode to reveal the Edit link, then open the account.
    await page.locator('#btnShowGroupSwitch').waitFor({ state: 'attached', timeout: 10000 }).catch(() => {});

    // The edit route is /account/<id>/edit. Resolve the id from the API list.
    // The raw fetch() must carry the XSRF-TOKEN header (decoded from the cookie)
    // or Laravel rejects it as Unauthenticated.
    const accountList = await page.evaluate(async () => {
      const xsrf = ('; ' + document.cookie).split('; XSRF-TOKEN=').pop()?.split(';').shift() ?? '';
      const decoded = decodeURIComponent(xsrf);
      const r = await fetch('/api/v1/twofaccounts', {
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decoded },
        credentials: 'same-origin',
      });
      if (!r.ok) return [];
      const j = await r.json();
      const items = Array.isArray(j) ? j : (j.data ?? []);
      return items.map((a: any) => ({ id: a.id, service: a.service }));
    });
    const created = accountList.find((a: any) => a.service === service);
    expect(created, 'created account should appear in the API list').toBeTruthy();

    await goto(`/account/${created.id}/edit`);
    await page.locator('#txtService').waitFor({ state: 'visible', timeout: 15000 });

    // --- Notes visible (persisted) ---
    const notesAfterCreate = page.locator('.field', { has: page.locator('.label', { hasText: /^Notes$/ }) }).locator('textarea').first();
    await expect(notesAfterCreate).toHaveValue(initialNotes, { timeout: 10000 });

    // --- Edit notes ---
    await notesAfterCreate.fill('');
    await notesAfterCreate.fill(updatedNotes);
    await page.locator('#btnUpdate').click();
    // updateAccount() also finishes with router.push({ name: 'accounts' });
    // wait for that navigation before proceeding (same race as create above).
    await expect(page).toHaveURL(/\/accounts/, { timeout: 15000 });
    await expect(page.getByText(service).first()).toBeVisible({ timeout: 15000 });

    // --- Verify the update persisted ---
    await goto(`/account/${created.id}/edit`);
    await page.locator('#txtService').waitFor({ state: 'visible', timeout: 15000 });
    const notesAfterEdit = page.locator('.field', { has: page.locator('.label', { hasText: /^Notes$/ }) }).locator('textarea').first();
    await expect(notesAfterEdit).toHaveValue(updatedNotes, { timeout: 10000 });
  });
});
