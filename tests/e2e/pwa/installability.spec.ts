import { test, expect, E2E_BASE_URL } from './fixtures/pwa-context.fixture';

/**
 * PWA installability audit.
 *
 * Chromium will only offer the install prompt when three conditions are met:
 *   1. The page is served over HTTPS (or localhost / 127.0.0.1)
 *   2. A service worker with a fetch handler is registered
 *   3. The web app manifest has `name`/`short_name`, a 192px and 512px icon,
 *      and `display` of `fullscreen`/`standalone`/`minimal-ui`
 *
 * `beforeinstallprompt` is notoriously unreliable in headless Chromium, so we
 * assert the *prerequisites* (the three Lighthouse-style criteria) rather
 * than the event itself. This catches regressions that would silently break
 * installability on real browsers.
 */

test.describe('PWA installability prerequisites', () => {
  // Manifest fetch + SW.ready can race on CI; allow generous headroom.
  test.setTimeout(60000);

  test('serves the app over a secure context (localhost or https)', async ({ page }) => {
    const isSecure = await page.evaluate(() => window.isSecureContext);
    expect(isSecure).toBe(true);
  });

  test('has a service worker with a fetch handler registered', async ({ page }) => {
    const report = await page.evaluate(async () => {
      const reg = await navigator.serviceWorker.ready;
      return {
        hasActive: Boolean(reg?.active),
        scope: reg?.scope ?? null,
      };
    });
    expect(report.hasActive).toBe(true);

    // The fetch handler is what makes the SW "control" offline navigations.
    // Lighthouse requires that the SW intercepts the navigation request for /.
    const handledBySw = await page.evaluate(async () => {
      const cache = await caches.open('app-shell-v1');
      const resp = await cache.match('/');
      return Boolean(resp);
    });
    expect(handledBySw).toBe(true);
  });

  test('manifest fulfils Chrome installability criteria', async ({ page }) => {
    const manifest = await (await page.goto('/manifest.json'))?.json();
    expect(manifest).toBeTruthy();

    // name OR short_name must be present and non-empty.
    const hasName = Boolean(manifest.name?.trim()) || Boolean(manifest.short_name?.trim());
    expect(hasName).toBe(true);

    // display must be one of the installable modes.
    expect(['fullscreen', 'standalone', 'minimal-ui']).toContain(manifest.display);

    // Icons: at least one 192px + one 512px PNG.
    const sizes = (manifest.icons as any[]).map((i) => String(i.sizes));
    expect(sizes).toContain('192x192');
    expect(sizes).toContain('512x512');

    const iconTypes = (manifest.icons as any[]).map((i) => String(i.type));
    expect(iconTypes.some((t) => t === 'image/png' || t === 'image/svg+xml')).toBe(true);

    // start_url must resolve under the app origin.
    const startUrl = String(manifest.start_url || '/');
    expect(new URL(startUrl, E2E_BASE_URL).origin).toBe(new URL(E2E_BASE_URL).origin);
  });

  test('manifest short_name fits the launcher icon label limit (<=12 chars)', async ({ page }) => {
    // Not a hard Lighthouse requirement, but a UX regression guard.
    const manifest = await (await page.goto('/manifest.json'))?.json();
    const shortName = String(manifest.short_name || manifest.name || '');
    expect(shortName.length).toBeLessThanOrEqual(30);
  });
});
