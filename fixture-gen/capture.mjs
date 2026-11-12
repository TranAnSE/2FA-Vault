/**
 * Playwright capture driver — stages a static serve dir (the real
 * argon2-browser UMD + wasm copied from node_modules, plus the capture page),
 * serves it with php -S, opens the page in the app repo's own chromium (the
 * same browser the e2e suite uses), waits for the vector payload, and writes
 * the CLI fixture file.
 *
 * One-off tooling for 2FA-Vault-CLI phase 1 (plan 261007-2255, RT-3).
 * Run from the app repo root:  node fixture-gen/capture.mjs
 */
import { chromium } from '@playwright/test';
import { spawn } from 'node:child_process';
import { writeFileSync, mkdirSync, copyFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.resolve(__dirname, '../../2FA-Vault-CLI/src/fixtures/parity-vectors.json');
const SERVE = path.join(__dirname, 'serve');
const DIST = path.resolve(__dirname, '../node_modules/argon2-browser/dist');
const PORT = 5199;

// Stage the serve dir: vendor binaries stay out of git (regenerated here).
mkdirSync(SERVE, { recursive: true });
for (const file of ['argon2-bundled.min.js', 'argon2.wasm', 'argon2-simd.wasm']) {
    copyFileSync(path.join(DIST, file), path.join(SERVE, file));
}

const server = spawn(
    process.env.PHP_BIN || 'php',
    ['-S', `127.0.0.1:${PORT}`, '-t', SERVE],
    { stdio: 'ignore' },
);

try {
    await new Promise((resolve, reject) => {
        server.on('error', reject);
        setTimeout(resolve, 800);
    });

    const browser = await chromium.launch();
    const page = await browser.newPage();
    page.on('pageerror', (err) => console.error('[pageerror]', err.message));
    await page.goto(`http://127.0.0.1:${PORT}/`, { waitUntil: 'load' });
    await page.waitForFunction(() => document.title === 'vectors-ready' || document.title === 'vectors-error', null, { timeout: 120_000 });
    const failure = await page.evaluate(() => window.__captureError ?? null);
    if (failure) throw new Error(`capture failed in browser: ${failure}`);
    const vectors = await page.evaluate(() => window.__vectors);
    await browser.close();

    mkdirSync(path.dirname(OUT), { recursive: true });
    writeFileSync(OUT, JSON.stringify(vectors, null, 2) + '\n');
    console.log(`captured ${vectors.argon2_raw.length} argon2 vectors, ${vectors.aes_envelopes.length} AES envelopes -> ${OUT}`);
    console.log('user agent:', vectors.user_agent);
} finally {
    server.kill();
}
