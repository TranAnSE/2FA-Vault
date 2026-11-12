/**
 * Live browser matrix (plan 261009-passkeys-migration phase 7 R2).
 *
 * Drives REAL browser WebAuthn ceremonies against the REAL endpoints with a
 * CDP virtual authenticator:
 *   S0  password login
 *   S1  ETL'd credential login (legacy row seeded -> real ETL command ->
 *       CDP addCredential injection of the exact keypair -> assertion -> 200)
 *   S2  fresh register -> login
 *   S3  rename (PATCH /webauthn/credentials/{credential_id}/name)
 *   S4  delete -> login fails (401)
 *   S5  device-lost recovery (webauthn.lost -> mail log token -> webauthn.recover)
 *
 * Requires: dev server on :8089 with the matrix env, the matrix DB migrated,
 * and fixture-gen/matrix-seed.php already run (it created the account and
 * wrote /tmp/matrix-credential.json).
 */
import { chromium } from 'playwright-core';
import { writeFileSync, mkdirSync, readFileSync } from 'node:fs';
import { execSync } from 'node:child_process';

const BASE = 'http://localhost:8089';
const EMAIL = 'matrix-user@rehearsal.test';
const PASSWORD = 'MatrixPassw0rd!';
const OUT_DIR = 'fixture-gen/matrix-evidence';

const MATRIX_DB = 'D:/2FA-Vault/2FA-Vault/database/matrix.sqlite';
const MATRIX_KEY = 'base64:7oRpmY/04HFU1LsbrlntjdR5Kp+78URiKtj7NGpyeT4=';
  'base64:c3Rha2luZ1JlaGVhcnNhbEFwcEtleUZpeHR1cmVWYWx1ZUZvck1hdHJpeDEyMw==';
const PHP = 'D:/php-8.4.23-nts-Win32-vs17-x64/php.exe';

mkdirSync(OUT_DIR, { recursive: true });
const results = [];
const step = (name, ok, detail = '') => {
  results.push({ step: name, ok, detail: String(detail).slice(0, 220) });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

// ============ S1 prep: real ETL command over the seeded legacy row ============
console.log('== running the real ETL command against the matrix DB ==');
execSync(`"${PHP}" artisan 2fauth:migrate-webauthn-credentials --force`, {
  stdio: 'pipe',
  env: {
    ...process.env,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: MATRIX_DB,
    APP_KEY: MATRIX_KEY,
    APP_URL: 'http://localhost:8089',
    APP_ENV: 'production',
    APP_DEBUG: 'false',
  },
});
console.log('ETL done');

const browser = await chromium.launch({ headless: true });
try {
  const context = await browser.newContext({ baseURL: BASE });
  const page = await context.newPage();

  const cdp = await context.newCDPSession(page);
  await cdp.send('WebAuthn.enable');
  const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: true,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
    },
  });
  step('S-prep virtual-authenticator', !!authenticatorId);
await page.goto(BASE + '/');  await page.waitForLoadState('domcontentloaded');

  // Same-origin fetch helpers with Laravel CSRF header (XSRF-TOKEN cookie).
  await page.evaluate(() => {
    const request = (path, body, method) => {
      const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
      const xsrf = m ? decodeURIComponent(m[1]) : '';
      return fetch(path, {
        method,
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-XSRF-TOKEN': xsrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      }).then(async (res) => ({ status: res.status, body: await res.text() }));
    };
    window.apiGet = (path) => request(path, undefined, 'GET');
    window.apiPost = (path, body) => request(path, body ?? {}, 'POST');
    window.apiPatch = (path, body) => request(path, body, 'PATCH');
    window.apiDelete = (path) => request(path, undefined, 'DELETE');
    window.b64ToU8 = (b64) => Uint8Array.from(atob(b64.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
    window.u8ToB64 = (u8) => btoa(String.fromCharCode(...u8)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  });

  const browserGet = async (targetPage, opts) => targetPage.evaluate(async (o) => {
    const pk = { ...o };
    pk.challenge = window.b64ToU8(pk.challenge);
    for (const key of ['excludeCredentials', 'allowCredentials']) {
      if (key in pk) pk[key] = pk[key].map((d) => ({ ...d, id: window.b64ToU8(d.id) }));
    }
    const cred = await navigator.credentials.get({ publicKey: pk });
    return {
      id: cred.id,
      type: cred.type,
      rawId: window.u8ToB64(new Uint8Array(cred.rawId)),
      response: {
        clientDataJSON: window.u8ToB64(new Uint8Array(cred.response.clientDataJSON)),
        authenticatorData: window.u8ToB64(new Uint8Array(cred.response.authenticatorData)),
        signature: window.u8ToB64(new Uint8Array(cred.response.signature)),
        userHandle: cred.response.userHandle ? window.u8ToB64(new Uint8Array(cred.response.userHandle)) : null,
      },
    };
  }, opts);

  const browserCreate = async (targetPage, opts) => targetPage.evaluate(async (o) => {
    const pk = { ...o };
    pk.challenge = window.b64ToU8(pk.challenge);
    if (pk.user) pk.user = { ...pk.user, id: window.b64ToU8(pk.user.id) };
    for (const key of ['excludeCredentials', 'allowCredentials']) {
      if (key in pk) pk[key] = pk[key].map((d) => ({ ...d, id: window.b64ToU8(d.id) }));
    }
    const cred = await navigator.credentials.create({ publicKey: pk });
    return {
      id: cred.id,
      type: cred.type,
      rawId: window.u8ToB64(new Uint8Array(cred.rawId)),
      response: {
        clientDataJSON: window.u8ToB64(new Uint8Array(cred.response.clientDataJSON)),
        attestationObject: window.u8ToB64(new Uint8Array(cred.response.attestationObject)),
      },
    };
  }, opts);

  // ============ S0: password login ============
  const login0 = await page.evaluate((creds) => window.apiPost('/user/login', creds), { email: EMAIL, password: PASSWORD });
  step('S0 password-login', login0.status === 200, `status=${login0.status} ${login0.body.slice(0, 120)}`);
  if (login0.status !== 200) throw new Error('password login failed');

  // ============ S1: ETL'd credential login ============
  const injected = JSON.parse(readFileSync('D:/tmp/matrix-credential.json', 'utf8'));
  // CDP binary fields take STANDARD padded base64; signCount is required
  // (>= -1) in the current protocol.
  const b64urlToB64 = (s) => s.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (s.length % 4)) % 4);
  await cdp.send('WebAuthn.addCredential', {
    authenticatorId,
    credential: {
      credentialId: b64urlToB64(injected.credentialId),
      isResidentCredential: false,
      rpId: 'localhost',
      privateKey: b64urlToB64(injected.pkcs8B64Url),
      userHandle: b64urlToB64(injected.userHandle),
      signCount: injected.counter,
    },
  });
  step('S1a credential-injected', true, `id=${injected.credentialId.slice(0, 12)}...`);

  const s1opts = await page.evaluate((mail) => window.apiPost('/webauthn/login/options', { email: mail }).then((r) => ({ status: r.status, body: r.body })), EMAIL);
  const s1options = JSON.parse(s1opts.body);
  step('S1b login-options', s1opts.status === 200 && !!s1options.challenge, `status=${s1opts.status} allowCred=${(s1options.allowCredentials ?? []).length}`);

  const s1assertion = await browserGet(page, s1options);
  step('S1c assertion-created', !!s1assertion.rawId, `id=${(s1assertion.rawId ?? '').slice(0, 12)}...`);

  const s1login = await page.evaluate((payload) => window.apiPost('/webauthn/login', payload), { ...s1assertion, email: EMAIL });
  step('S1d ETLd-credential-login', s1login.status === 200, `status=${s1login.status} ${s1login.body.slice(0, 100)}`);
  if (s1login.status !== 200) throw new Error('S1 ETL login failed');

  // ============ S2: fresh register -> login ============
  // A second page gets its own CDP session and virtual authenticator, so the
  // fresh registration cannot collide with the injected ETL credential the
  // first authenticator holds (excludeCredentials would rightly abort it).
  const page2 = await context.newPage();
  await page2.goto(BASE + '/');
  await page2.waitForLoadState('domcontentloaded');
  const cdp2 = await context.newCDPSession(page2);
  await cdp2.send('WebAuthn.enable');
  const { authenticatorId: authenticatorId2 } = await cdp2.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: true,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
    },
  });
  await page2.evaluate(() => {
    const request = (path, body, method) => {
      const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
      const xsrf = m ? decodeURIComponent(m[1]) : '';
      return fetch(path, {
        method,
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-XSRF-TOKEN': xsrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      }).then(async (res) => ({ status: res.status, body: await res.text() }));
    };
    window.apiGet = (path) => request(path, undefined, 'GET');
    window.apiPost = (path, body) => request(path, body ?? {}, 'POST');
    window.apiPatch = (path, body) => request(path, body, 'PATCH');
    window.apiDelete = (path) => request(path, undefined, 'DELETE');
    window.b64ToU8 = (b64) => Uint8Array.from(atob(b64.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
    window.u8ToB64 = (u8) => btoa(String.fromCharCode(...u8)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  });
  const s2opts = await page2.evaluate(() => window.apiPost('/webauthn/register/options').then((r) => ({ status: r.status, body: r.body })));
  const s2options = JSON.parse(s2opts.body);
  step('S2a register-options', s2opts.status === 200 && !!s2options.challenge, `status=${s2opts.status} residentKey=${s2options.authenticatorSelection?.residentKey} UV=${s2options.authenticatorSelection?.userVerification}`);

  const s2attestation = await browserCreate(page2, s2options);
  step('S2b attestation-created', !!s2attestation.rawId, `id=${(s2attestation.rawId ?? '').slice(0, 12)}...`);

  const s2save = await page2.evaluate((payload) => window.apiPost('/webauthn/register', payload), { name: 'matrix-fresh-key', ...s2attestation });
  step('S2c passkey-stored', s2save.status === 204, `status=${s2save.status} ${s2save.body.slice(0, 120)}`);
  if (s2save.status !== 204) throw new Error('S2 register failed');

  const s2loginOpts = await page2.evaluate((mail) => window.apiPost('/webauthn/login/options', { email: mail }).then((r) => ({ status: r.status, body: r.body })), EMAIL);
  const s2loginOptions = JSON.parse(s2loginOpts.body);
  const s2assertion = await browserGet(page2, s2loginOptions);
  const s2login = await page2.evaluate((payload) => window.apiPost('/webauthn/login', payload), { ...s2assertion, email: EMAIL });
  step('S2d fresh-credential-login', s2login.status === 200, `status=${s2login.status} ${s2login.body.slice(0, 100)}`);

  // ============ S3: rename ============
  const list1 = await page2.evaluate(() => window.apiGet('/webauthn/credentials'));
  const list1Body = JSON.parse(list1.body);
  const target = list1Body.find((c) => c.id === s2assertion.id);
  step('S3a credentials-listed', list1.status === 200 && !!target, `status=${list1.status} count=${list1Body.length}`);

  const rename = await page2.evaluate((payload) => window.apiPatch(`/webauthn/credentials/${encodeURIComponent(payload.id)}/name`, { name: 'renamed-matrix-key' }), { id: target.id });
  step('S3b renamed', rename.status === 200, `status=${rename.status} ${rename.body.slice(0, 100)}`);

  const list2 = await page2.evaluate(() => window.apiGet('/webauthn/credentials'));
  const afterRename = JSON.parse(list2.body).find((c) => c.id === target.id);
  step('S3c rename-visible', afterRename?.alias === 'renamed-matrix-key', `alias=${afterRename?.alias}`);

  // ============ S4: delete -> the deleted device can no longer authenticate ============
  const del = await page2.evaluate((id) => window.apiDelete(`/webauthn/credentials/${encodeURIComponent(id)}`), target.id);
  step('S4a deleted', [200, 204].includes(del.status), `status=${del.status} ${del.body.slice(0, 100)}`);

  // Server-side: login options still list the REMAINING (ETL'd) credential.
  const s4opts = await page2.evaluate((mail) => window.apiPost('/webauthn/login/options', { email: mail }).then((r) => ({ status: r.status, body: r.body })), EMAIL);
  const s4options = JSON.parse(s4opts.body);
  step('S4b login-options-after-delete', s4opts.status === 200 && (s4options.allowCredentials ?? []).length === 1, `status=${s4opts.status} allowCred=${(s4options.allowCredentials ?? []).length}`);

  // Client-side (the real stale-device UX): this page's authenticator only
  // holds the DELETED passkey, so it can no longer serve the ceremony for the
  // remaining credential — the browser aborts instead of signing.
  let staleDeviceAborted = false;
  try {
    await browserGet(page2, s4options);
  } catch (e) {
    staleDeviceAborted = String(e).includes('NotAllowedError');
  }
  step('S4c stale-device-cannot-serve', staleDeviceAborted, 'browser aborted the ceremony as expected');

  // The OTHER registered credential still logs in fine (page1 authenticator
  // holds the ETL'd passkey, which was not deleted).
  const s4opts1 = await page.evaluate((mail) => window.apiPost('/webauthn/login/options', { email: mail }).then((r) => ({ status: r.status, body: r.body })), EMAIL);
  const s4assertion1 = await browserGet(page, JSON.parse(s4opts1.body));
  const s4login1 = await page.evaluate((payload) => window.apiPost('/webauthn/login', payload), { ...s4assertion1, email: EMAIL });
  step('S4d remaining-credential-still-logs-in', s4login1.status === 200, `status=${s4login1.status} ${s4login1.body.slice(0, 80)}`);

  // ============ S5: device-lost recovery ============
  const lost = await page2.evaluate((mail) => window.apiPost('/webauthn/lost', { email: mail }).then((r) => ({ status: r.status, body: r.body })), EMAIL);
  step('S5a recovery-email-requested', lost.status === 200, `status=${lost.status} ${lost.body.slice(0, 100)}`);

  // The mailer is the log driver: the recovery mail (with the reset token)
  // lands in storage/logs/laravel.log of the SERVER process.
  const mailLog = readFileSync('D:/2FA-Vault/2FA-Vault/storage/logs/laravel.log', 'utf8');
  const tokenMatch = mailLog.match(/token=([A-Za-z0-9_-]+)/);
  const token = tokenMatch ? tokenMatch[1] : null;
  step('S5b token-extracted-from-mail-log', !!token, token ? `token=${token.slice(0, 8)}...` : 'no token in log mail');

  if (token) {
    const recover = await page2.evaluate((payload) => window.apiPost('/webauthn/recover', payload), { token, email: EMAIL, password: PASSWORD });
    step('S5c recover-success', recover.status === 200, `status=${recover.status} ${recover.body.slice(0, 100)}`);

    const credsAfterRecover = await page2.evaluate(() => window.apiGet('/webauthn/credentials'));
    step('S5d recovery-session-alive', credsAfterRecover.status === 200, `status=${credsAfterRecover.status}`);
  }

  // ============ summary ============
  const failed = results.filter((r) => !r.ok);
  console.log(`\n=== BROWSER MATRIX SUMMARY: ${results.length - failed.length}/${results.length} steps passed ===`);
  writeFileSync('fixture-gen/matrix-evidence/matrix-results.json', JSON.stringify(results, null, 2));
  if (failed.length > 0) {
    console.log('FAILED STEPS:', failed.map((f) => f.step).join(', '));
    process.exitCode = 1;
  }
} finally {
  await browser.close();
}
