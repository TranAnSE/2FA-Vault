/**
 * E2E server starter: builds frontend, sets up DB, starts php artisan serve.
 * Serves built assets via Laravel (no Vite dev server needed).
 */
import { execSync, spawn } from 'child_process';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '../..');
const EXT_ROOT = path.resolve(ROOT, '../2FA-Vault-WebExtension');
const EXT_DIST_MANIFEST = path.resolve(EXT_ROOT, 'dist/chrome-mv3/manifest.json');
const DB_PATH = path.resolve(ROOT, 'database/database_e2e.sqlite');
const IS_CI = process.env.CI === 'true' || process.env.CI === '1';
// Overridable so WebAuthn/PRF e2e can run on `localhost` (the RP id falls
// back to the app host, and Chromium rejects an IP-address rp.id with
// "This is an invalid domain" — `localhost` is the only valid loopback name).
const E2E_ORIGIN = process.env.E2E_ORIGIN || 'http://127.0.0.1:8001';
const E2E_ENV = {
  ...process.env,
  APP_ENV: 'e2e',
  APP_URL: E2E_ORIGIN,
  ASSET_URL: E2E_ORIGIN,
  // Keep the WebAuthn RP id + allowed origins in lockstep with the served
  // origin (real env vars override .env.e2e via phpdotenv immutability).
  // NOTE: Chromium also binds rp.id to the PAGE origin's host, so a spec
  // that needs an rp.id of `localhost` MUST browse from localhost AND the
  // server must serve from localhost — hence E2E_ORIGIN=http://localhost:8001
  // for prf-unlock.spec.ts (see its header).
  WEBAUTHN_ID: new URL(E2E_ORIGIN).hostname,
  WEBAUTHN_ORIGINS: E2E_ORIGIN,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: DB_PATH,
  // Pin session + CSP defaults explicitly. `php artisan serve` spawns a
  // fresh PHP worker for every request; relying solely on .env.e2e being
  // picked up has proven flaky in CI, so we also surface critical values
  // through the process environment.
  SESSION_DRIVER: 'file',
  SESSION_SECURE_COOKIE: 'false',
  SESSION_SAME_SITE: 'lax',
  SESSION_DOMAIN: '',
  // File, not array: `php artisan serve` spawns a fresh PHP worker per
  // request, and cross-request state (backup-restore confirmation tokens,
  // rate limiters) must survive between them. CACHE_STORE is what
  // config/cache.php reads FIRST — CI serves with .env.testing (copied to
  // .env, no .env.e2e exists there) whose CACHE_STORE=array would silently
  // beat CACHE_DRIVER, evaporating the restore token between workers.
  CACHE_STORE: 'file',
  CACHE_DRIVER: 'file',
  // .env.testing defines no THROTTLE_* so the 60/min api default would arm
  // against the whole suite once the file cache is shared across workers.
  THROTTLE_API: '1000,1',
  THROTTLE_LOGIN: '1000,1',
  CONTENT_SECURITY_POLICY: 'false',
};

function runCommand(command, label, options = {}) {
  try {
    return execSync(command, {
      cwd: options.cwd ?? ROOT,
      env: options.env ?? E2E_ENV,
      stdio: options.stdio ?? 'pipe',
      timeout: options.timeout,
    });
  } catch (error) {
    const stdout = error.stdout?.toString?.() ?? '';
    const stderr = error.stderr?.toString?.() ?? '';
    console.error(`[E2E Server] ${label} failed.`);
    if (stdout) console.error(`[E2E Server] ${label} stdout:\n${stdout}`);
    if (stderr) console.error(`[E2E Server] ${label} stderr:\n${stderr}`);
    throw error;
  }
}

function removeFileWithRetry(filePath, label) {
  if (!fs.existsSync(filePath)) {
    return;
  }

  for (let attempt = 1; attempt <= 5; attempt += 1) {
    try {
      fs.rmSync(filePath, { force: true });
      console.log(`[E2E Server] Removed ${label}.`);
      return;
    } catch (error) {
      if (attempt === 5) {
        throw error;
      }

      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 250);
    }
  }
}

// Remove stale Vite hot file (forces @vite() to use production build)
const hotFile = path.resolve(ROOT, 'public/hot');
removeFileWithRetry(hotFile, 'stale Vite hot file');

// Recreate the E2E SQLite file on every run. `migrate:fresh` cannot recover
// from a malformed SQLite file because Laravel opens it before dropping tables.
removeFileWithRetry(DB_PATH, 'stale E2E SQLite database');
fs.writeFileSync(DB_PATH, '');

// Run migrations and seed
console.log('[E2E Server] Running migrations...');
runCommand('php artisan config:clear', 'config:clear');
runCommand('php artisan route:clear', 'route:clear');
runCommand('php artisan migrate:fresh --env=e2e --force', 'migrate:fresh');
// The file cache persists across runs (needed WITHIN a run: cross-request
// state like restore tokens), so stale rate-limit counters accumulate
// between runs. Reset them — each run starts with a clean limiter budget.
runCommand('php artisan cache:clear --env=e2e', 'cache:clear');
console.log('[E2E Server] Seeding database...');
runCommand('php artisan db:seed --class=E2eSeeder --env=e2e --force', 'db:seed');
console.log('[E2E Server] Ensuring Passport personal access client...');
// Passport v13's passport:client uses an interactive choice() even with
// --no-interaction, so use our non-interactive artisan command instead.
runCommand('php artisan passport:create-personal-client --name="E2E Personal Access Client" --env=e2e --no-interaction', 'passport:client');

// Build frontend deterministically in CI, otherwise reuse existing build if present.
const buildManifest = path.resolve(ROOT, 'public/build/manifest.json');
if (IS_CI || !fs.existsSync(buildManifest)) {
  if (IS_CI) {
    console.log('[E2E Server] CI detected - rebuilding frontend assets for fresh E2E runtime...');
  } else {
    console.log('[E2E Server] Building frontend assets...');
  }
  runCommand('npm run build', 'npm run build', { timeout: 120000 });
} else {
  console.log('[E2E Server] Using existing build assets.');
}

if (IS_CI || !fs.existsSync(EXT_DIST_MANIFEST)) {
  if (IS_CI) {
    console.log('[E2E Server] CI detected - rebuilding extension assets for popup E2E runtime...');
  } else {
    console.log('[E2E Server] Building extension assets...');
  }
  runCommand('npm run build', 'extension npm run build', {
    cwd: EXT_ROOT,
    timeout: 120000,
  });
} else {
  console.log('[E2E Server] Using existing extension build assets.');
}

const manifestJson = JSON.parse(fs.readFileSync(buildManifest, 'utf8'));
const appEntry = manifestJson['resources/js/app.js'];

if (!appEntry?.file) {
  throw new Error('[E2E Server] Missing Vite manifest entry for resources/js/app.js');
}

const appEntryPath = path.resolve(ROOT, 'public/build', appEntry.file);
if (!fs.existsSync(appEntryPath)) {
  throw new Error(`[E2E Server] Missing built app entry: ${appEntryPath}`);
}

console.log(`[E2E Server] Verified built app entry: ${appEntry.file}`);

// On Windows, `php artisan serve` worker processes can outlive Playwright's
// webServer teardown (the wrapper dies, the PHP children don't), leaving a
// stale server bound to the port that serves the PREVIOUS run's origin env.
// A fresh run then fails with cross-origin asset loads (CORS) and an
// apparently-booted-but-dead SPA. Kill any stale PHP listener before
// binding — scoped to php.exe (review fix): an unrelated process that
// legitimately owns the port is left alone and the bind failure surfaces
// normally instead.
try {
  if (process.platform === 'win32') {
    const pids = execSync(
      'powershell -NoProfile -Command "(Get-NetTCPConnection -LocalPort 8001 -State Listen).OwningProcess | Sort-Object -Unique | Where-Object { (Get-Process -Id $_ -ErrorAction SilentlyContinue).ProcessName -eq \'php\' }"',
      { stdio: ['ignore', 'pipe', 'ignore'] },
    ).toString().split(/\r?\n/).map((s) => s.trim()).filter((s) => /^\d+$/.test(s));
    for (const pid of pids) {
      try {
        execSync(`taskkill /PID ${pid} /F`, { stdio: 'ignore' });
        console.log(`[E2E Server] Killed stale PHP listener on port 8001 (pid ${pid}).`);
      } catch { /* pid may have exited already */ }
    }
  } else {
    // POSIX: fuser has no process-name filter; documented macOS caveat
    // (fuser is absent on stock macOS) — CI runs Linux.
    execSync('fuser -k 8001/tcp', { stdio: 'ignore' });
  }
} catch { /* port free — nothing to do */ }

console.log('[E2E Server] Starting Laravel on port 8001...');

// Start the Laravel server (blocking)
const server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=8001', '--env=e2e'], {
  cwd: ROOT,
  env: E2E_ENV,
  stdio: 'inherit',
});

server.on('error', (err) => {
  console.error('[E2E Server] Failed to start:', err);
  process.exit(1);
});

process.on('SIGTERM', () => { server.kill('SIGTERM'); process.exit(0); });
process.on('SIGINT', () => { server.kill('SIGINT'); process.exit(0); });
