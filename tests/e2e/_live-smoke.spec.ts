import { test, expect } from './fixtures/live-app.fixture';

test.describe('live-app smoke', () => {
  test('shared user logs in, completes E2EE, and reaches an authed route', async ({ authedPage: page }) => {
    // After E2EE setup the app routes to /accounts (or /start for an empty vault).
    await expect(page).toHaveURL(/\/(accounts|start)(\b|\/|$)/);
    await expect(page.locator('#app')).toBeVisible();
  });
});
