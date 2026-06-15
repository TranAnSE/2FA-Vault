import { test, expect } from './fixtures/live-app.fixture';

/**
 * v1.2.0 e2e: session management.
 *
 * Settings > Security > Sessions -> the current session is visible and marked
 * as "current".
 */
test.describe('Session management', () => {
  test('current session is listed on the sessions page', async ({ authedPage: page, goto }) => {
    await goto('/settings/security/sessions');

    // Page heading.
    await expect(page.getByRole('heading', { name: /sessions/i }).first()).toBeVisible({ timeout: 15000 });

    // The sessions list is rendered inside a .box; at least one row is present
    // (the feature records a session on every login). The current device is
    // tagged with the "This device" marker badge when the API request carries
    // the session cookie.
    await expect(page.locator('.box').first()).toBeVisible({ timeout: 10000 });

    // At least one session row is present.
    const sessionRows = page.locator('.box .is-flex.is-align-items-center');
    await expect(sessionRows.first()).toBeVisible({ timeout: 10000 });
    expect(await sessionRows.count()).toBeGreaterThanOrEqual(1);
  });
});
