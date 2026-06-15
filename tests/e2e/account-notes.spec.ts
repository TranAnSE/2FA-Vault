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
    await page.locator('input[name="service"]').waitFor({ state: 'visible', timeout: 15000 });
    await page.locator('input[name="service"]').fill(service);
    await page.locator('input[name="account"]').fill('notes@test.local');
    // Select TOTP so the secret field appears (FormToggle renders the choice text).
    await page.getByText('TOTP', { exact: true }).click();
    await page.locator('input[name="secret"]').waitFor({ state: 'visible', timeout: 5000 });
    await page.locator('input[name="secret"]').fill('A4GRFTVVRBGY7UIW');

    // Fill the notes textarea. The "Notes" <label> has no `for` and the
    // <textarea> has no id, so getByLabel cannot associate them — target the
    // textarea inside the field whose label reads "Notes".
    const notesTextarea = page.locator('.field', { has: page.locator('.label', { hasText: /^Notes$/ }) }).locator('textarea').first();
    await notesTextarea.waitFor({ state: 'visible', timeout: 10000 });
    await notesTextarea.fill(initialNotes);

    await page.locator('#btnCreate').click();
    await goto('/accounts');
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
    await page.locator('input[name="service"]').waitFor({ state: 'visible', timeout: 15000 });

    // --- Notes visible (persisted) ---
    const notesAfterCreate = page.locator('.field', { has: page.locator('.label', { hasText: /^Notes$/ }) }).locator('textarea').first();
    await expect(notesAfterCreate).toHaveValue(initialNotes, { timeout: 10000 });

    // --- Edit notes ---
    await notesAfterCreate.fill('');
    await notesAfterCreate.fill(updatedNotes);
    await page.locator('#btnUpdate').click();
    await goto('/accounts');
    await expect(page.getByText(service).first()).toBeVisible({ timeout: 15000 });

    // --- Verify the update persisted ---
    await goto(`/account/${created.id}/edit`);
    await page.locator('input[name="service"]').waitFor({ state: 'visible', timeout: 15000 });
    const notesAfterEdit = page.locator('.field', { has: page.locator('.label', { hasText: /^Notes$/ }) }).locator('textarea').first();
    await expect(notesAfterEdit).toHaveValue(updatedNotes, { timeout: 10000 });
  });
});
