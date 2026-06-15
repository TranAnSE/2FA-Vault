import { test, expect } from './fixtures/live-app.fixture';

/**
 * v1.2.0 e2e: secure notes.
 *
 * Notes section -> create a markdown note (title + markdown body) -> Preview tab
 * -> rendered HTML visible -> assert an XSS payload is sanitized out -> pin ->
 * delete -> removed from list.
 */
test.describe('Secure notes', () => {
  test('markdown note renders sanitized preview, pins, and deletes', async ({ authedPage: page, goto }) => {
    const title = `SecureNote-${Date.now().toString(36)}`;
    // Markdown body + an XSS payload. DOMPurify must strip the <script> tag.
    const body = [
      '# Heading one',
      '',
      'Some **bold** text and `inline code`.',
      '',
      '<script>alert(1)</script>',
      '',
      '<a href="https://example.com">link</a>',
    ].join('\n');

    // --- Navigate to the Notes section and start the editor ---
    await goto('/secure-notes');
    await expect(page.getByRole('heading', { name: /secure notes/i }).first()).toBeVisible({ timeout: 15000 });

    const newNoteBtn = page.getByRole('button', { name: /new note/i }).first();
    await expect(newNoteBtn).toBeEnabled({ timeout: 10000 });
    await newNoteBtn.click();

    // Wait for the editor heading to mount.
    await expect(page.getByRole('heading', { name: /new note/i }).first()).toBeVisible({ timeout: 10000 });

    // --- Create note: title + markdown body ---
    const titleInput = page.locator('input[type="text"]').first();
    await titleInput.waitFor({ state: 'visible', timeout: 10000 });
    await titleInput.fill(title);

    // Ensure content type is markdown.
    const contentTypeSelect = page.locator('div.field select').first();
    await contentTypeSelect.selectOption('markdown');

    // Edit-mode textarea holds the raw markdown.
    const textarea = page.locator('textarea').first();
    await textarea.waitFor({ state: 'visible', timeout: 10000 });
    await textarea.fill(body);

    // --- Save the note (routes back to the list) ---
    const saveBtn = page.getByRole('button', { name: /^save$/i }).first();
    await saveBtn.click();
    await expect(page.getByRole('heading', { name: /secure notes/i }).first()).toBeVisible({ timeout: 15000 });
    // The note title should now appear in the list.
    await expect(page.getByText(title, { exact: true }).first()).toBeVisible({ timeout: 15000 });

    // --- Reopen the note to test the Preview tab ---
    await page.getByText(title, { exact: true }).first().click();
    await expect(page.getByRole('heading', { name: /edit note/i }).first()).toBeVisible({ timeout: 10000 });

    // Switch to Preview.
    const previewBtn = page.getByRole('button', { name: /^preview$/i }).first();
    await previewBtn.click();

    // Rendered HTML container.
    const previewBox = page.locator('.note-preview').first();
    await expect(previewBox).toBeVisible({ timeout: 10000 });

    // Rendered markdown: heading + bold + code + link should be present as HTML.
    await expect(previewBox.locator('h1').first()).toHaveText(/heading one/i);
    await expect(previewBox.locator('strong').first()).toHaveText(/bold/i);
    await expect(previewBox.locator('code').first()).toHaveText(/inline code/i);
    // The link is rendered as an <a> pointing at example.com (markdown-it
    // linkify normalizes the raw anchor's text to the URL, so assert on href
    // rather than link text).
    await expect(previewBox.locator('a').first()).toHaveAttribute('href', 'https://example.com');

    // --- XSS assertion: the <script> tag must be sanitized out of the DOM ---
    await expect(previewBox.locator('script')).toHaveCount(0);
    // And no executable script payload survives in the rendered HTML.
    const renderedHtml = await previewBox.innerHTML();
    expect(renderedHtml.toLowerCase()).not.toContain('<script');

    // --- Pin the note from the list ---
    await goto('/secure-notes');
    await expect(page.getByText(title, { exact: true }).first()).toBeVisible({ timeout: 15000 });
    const noteRow = page.locator('.is-flex.is-justify-content-space-between', { has: page.getByText(title, { exact: true }) }).first();
    const pinBtn = noteRow.locator('button.is-ghost').first();
    await expect(pinBtn).toBeVisible();
    await pinBtn.click();
    // Pinned -> the pin button gets the warning (pinned) class.
    await expect(pinBtn).toHaveClass(/has-text-warning/, { timeout: 10000 });

    // --- Delete the note ---
    const deleteBtn = noteRow.getByRole('button').last();
    // Confirm dialog appears (window.confirm). Auto-accept it.
    page.once('dialog', (d) => d.accept().catch(() => {}));
    await deleteBtn.click();

    // After deletion the note is gone from the list.
    await expect(page.getByText(title, { exact: true })).toHaveCount(0, { timeout: 15000 });
  });
});
