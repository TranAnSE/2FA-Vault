import { test, expect, E2E_BASE_URL } from './fixtures/pwa-context.fixture';

/**
 * PWA service-worker lifecycle and web app manifest tests.
 *
 * These cover the "installability prerequisites" half of PWA behaviour:
 * the SW registers, claims the page, precaches the app shell, and the
 * manifest is fetchable and schema-valid. The offline OTP access path is
 * exercised in offline-access.spec.ts.
 */

test.describe('PWA service worker & manifest', () => {
  // SW activation + app-shell precache can be slow on CI runners.
  test.setTimeout(90000);

  test('registers /sw.js at scope / and reaches the active state', async ({ page }) => {
    const reg = await page.evaluate(async () => {
      const registration = await navigator.serviceWorker.ready;
      return {
        scope: registration.scope,
        scriptURL: registration.active?.scriptURL ?? null,
        state: registration.active?.state ?? null,
      };
    });

    expect(reg.state).toBe('activated');
    expect(reg.scriptURL).toMatch(/\/sw\.js$/);
    expect(reg.scope).toBe(new URL('/', E2E_BASE_URL).href);
  });

  test('precaches the app shell in the app-shell-v1 cache', async ({ page }) => {
    const cacheReport = await page.evaluate(async () => {
      const keys = await caches.keys();
      const shellCache = keys.find((k) => k.startsWith('app-shell-'));
      if (!shellCache) return { keys, shellCached: [] as string[] };

      const cache = await caches.open(shellCache);
      const requests = await cache.keys();
      return {
        keys,
        shellCached: requests.map((r) => new URL(r.url).pathname),
      };
    });

    expect(cacheReport.keys.some((k) => k.startsWith('app-shell-'))).toBeTruthy();
    // The SW precache list in public/sw.js contains at least these core assets.
    for (const expectedPath of ['/', '/manifest.json']) {
      expect(cacheReport.shellCached).toContain(expectedPath);
    }
  });

  test('serves the navigation request from the app-shell cache on repeat visit', async ({ page }) => {
    // Force a second navigation so the SW fetch handler can answer from cache.
    await page.goto('/about');
    await page.waitForLoadState('networkidle').catch(() => {});

    const cachedRoot = await page.evaluate(async () => {
      const cache = await caches.open('app-shell-v1');
      const resp = await cache.match('/');
      return resp ? resp.status : null;
    });

    expect(cachedRoot).toBe(200);
  });

  test('exposes a valid web app manifest', async ({ page }) => {
    const response = await page.goto('/manifest.json');
    expect(response?.status()).toBe(200);

    const manifest = await response?.json();
    expect(manifest).toBeTruthy();
    expect(typeof manifest.name).toBe('string');
    expect(manifest.name.length).toBeGreaterThan(0);
    expect(manifest.display).toBe('standalone');
    expect(Array.isArray(manifest.icons)).toBe(true);
    expect(manifest.icons.length).toBeGreaterThanOrEqual(2);

    // At least one icon must declare a maskable purpose for Android safe-area.
    const hasMaskable = manifest.icons.some((i: any) =>
      String(i.purpose || '').split(/\s+/).includes('maskable'),
    );
    expect(hasMaskable).toBe(true);

    // At least one 192px and one 512px icon (Chrome installability requirement).
    const sizes = manifest.icons.map((i: any) => String(i.sizes));
    expect(sizes).toContain('192x192');
    expect(sizes).toContain('512x512');
  });

  test('manifest is linked from the document head', async ({ page }) => {
    await page.goto('/accounts');
    await page.waitForLoadState('networkidle').catch(() => {});

    const linkHref = await page
      .locator('link[rel="manifest"]')
      .first()
      .getAttribute('href');
    expect(linkHref).toBeTruthy();
    expect(String(linkHref)).toMatch(/manifest\.json$/);
  });
});
