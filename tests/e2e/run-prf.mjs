/**
 * PRF spec launcher (review fix): bakes the mandatory localhost origin so a
 * bare `npm run test:e2e:prf` cannot repeat the 127.0.0.1 failure mode
 * (Chromium binds the WebAuthn rp.id to the PAGE origin's host — an IP
 * origin makes every ceremony reject with "This is an invalid domain",
 * and a mixed localhost/IP run 403s the built-asset loads). An explicit
 * E2E_ORIGIN in the environment still wins for experimentation.
 * Extra CLI args are passed through to Playwright.
 */
process.env.E2E_ORIGIN = process.env.E2E_ORIGIN || 'http://localhost:8001';

const { spawnSync } = await import('node:child_process');

const result = spawnSync(
    'npx',
    ['playwright', 'test', 'tests/e2e/prf-unlock.spec.ts', '--workers=1', '--reporter=line', ...process.argv.slice(2)],
    { stdio: 'inherit', shell: process.platform === 'win32' },
);

process.exit(result.status ?? 1);
