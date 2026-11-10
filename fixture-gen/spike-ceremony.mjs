/**
 * Spike ceremony driver (plan 261009-passkeys-migration, Phase 1).
 *
 * Drives REAL browser WebAuthn ceremonies (Chromium + CDP virtual
 * authenticator) through both stacks:
 *   A. laragear register + laragear login sanity (the "before" path)
 *   B. spike ETL conversion of the laragear row -> passkeys row
 *   C. assertion against the CONVERTED record through VerifyPasskey (R2 gate)
 *   D. fresh passkeys registration + verification through package actions (R3)
 *
 * Run from app repo root with the dev server on :8089 + spike.sqlite:
 *   node fixture-gen/spike-ceremony.mjs
 */
import { chromium } from 'playwright-core';
import { writeFileSync, mkdirSync } from 'node:fs';

const BASE = 'http://localhost:8089';
const EMAIL = 'spike-user@example.test';
const PASSWORD = 'Spike-Passw0rd!';
const NAME = 'Spike User';
const OUT_DIR = 'fixture-gen/spike-evidence';

mkdirSync(OUT_DIR, { recursive: true });
const results = [];
const step = (name, ok, detail = '') => {
  results.push({ step: name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

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
  step('virtual-authenticator', !!authenticatorId);

  await page.goto(BASE + '/');
  await page.waitForLoadState('domcontentloaded');

  // Same-origin fetch helper with Laravel CSRF header (XSRF-TOKEN cookie).
  await page.evaluate(() => {
    window.apiPost = (path, body) => {
      const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
      const xsrf = m ? decodeURIComponent(m[1]) : '';
      return fetch(path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-XSRF-TOKEN': xsrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body ?? {}),
      }).then(async (res) => ({ status: res.status, body: await res.text() }));
    };
    window.b64ToU8 = (b64) => Uint8Array.from(atob(b64.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
    window.u8ToB64 = (u8) => btoa(String.fromCharCode(...u8)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  });

  // ============ A. account + laragear credential ============
  const reg = await page.evaluate(({ EMAIL, PASSWORD, NAME }) => window.apiPost('/user', {
    name: NAME, email: EMAIL, password: PASSWORD, password_confirmation: PASSWORD,
  }), { EMAIL, PASSWORD, NAME });
  step('A1 account-registered', reg.status === 200 || reg.status === 201, `status=${reg.status} ${reg.body.slice(0, 120)}`);

  // The register endpoint may auto-login; force a clean login for a known session state.
  const login = await page.evaluate(({ EMAIL, PASSWORD }) => window.apiPost('/user/login', { email: EMAIL, password: PASSWORD }), { EMAIL, PASSWORD });
  step('A2 password-login', login.status === 200, `status=${login.status} ${login.body.slice(0, 120)}`);

  const laragearOpts = await page.evaluate(() => window.apiPost('/webauthn/register/options').then(async (res) => {
    if (res.status !== 200) return { status: res.status, raw: res.body, options: null };
    const options = JSON.parse(res.body);
    return { status: res.status, options };
  }));
  step('A3 laragear-register-options', laragearOpts.status === 200 && !!laragearOpts.options?.challenge,
    `status=${laragearOpts.status} keys=${laragearOpts.options ? Object.keys(laragearOpts.options).join(',') : 'none'}`);
  writeFileSync(`${OUT_DIR}/laragear-register-options.json`, JSON.stringify(laragearOpts.options, null, 2));

  const attestation = await page.evaluate(async (opts) => {
    const pk = { ...opts };
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
  }, laragearOpts.options).catch((e) => ({ error: String(e) }));
  step('A4 browser-attestation-created', !attestation.error, attestation.error ?? `id=${attestation.id.slice(0, 16)}...`);
  if (attestation.error) throw new Error('attestation failed: ' + attestation.error);
  writeFileSync(`${OUT_DIR}/laragear-attestation.json`, JSON.stringify(attestation, null, 2));

  const laragearSave = await page.evaluate((cred) => window.apiPost('/webauthn/register', cred), attestation);
  step('A5 laragear-credential-saved', laragearSave.status === 204, `status=${laragearSave.status} ${laragearSave.body.slice(0, 120)}`);

  // Sanity: the laragear login path works with this credential (the "before").
  const laragearLoginOpts = await page.evaluate(({ EMAIL }) => window.apiPost('/webauthn/login/options', { email: EMAIL }).then(async (res) => ({ status: res.status, options: JSON.parse(res.body) })), { EMAIL });
  step('A6 laragear-login-options', laragearLoginOpts.status === 200 && !!laragearLoginOpts.options?.challenge, `status=${laragearLoginOpts.status}`);

  const assertion = await page.evaluate(async (opts) => {
    const pk = { ...opts };
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
  }, laragearLoginOpts.options).catch((e) => ({ error: String(e) }));
  step('A7 browser-assertion-created', !assertion.error, assertion.error ?? `userHandle=${assertion.userHandle ? assertion.userHandle.length + 'chars-b64' : 'null'}`);
  writeFileSync(`${OUT_DIR}/laragear-assertion.json`, JSON.stringify(assertion, null, 2));

  const laragearLogin = await page.evaluate(({ cred, EMAIL }) => window.apiPost('/webauthn/login', { ...cred, email: EMAIL }), { cred: assertion, EMAIL });
  step('A8 laragear-login-works', laragearLogin.status === 200, `status=${laragearLogin.status} ${laragearLogin.body.slice(0, 100)}`);

  // ============ B. ETL conversion of the laragear row ============
  const etl = await page.evaluate(() => window.apiPost('/spike/etl'));
  let etlBody = null;
  try { etlBody = JSON.parse(etl.body); } catch { /* keep raw */ }
  step('B1 spike-ETL', etl.status === 200 && etlBody?.report?.every((r) => r.result === 'OK'),
    `status=${etl.status} report=${JSON.stringify(etlBody?.report ?? etl.body.slice(0, 200))} passkeys=${etlBody?.passkeys}`);

  // ============ C. assertion against the CONVERTED record (R2 gate) ============
  const pkOpts = await page.evaluate((EMAIL) => window.apiPost('/spike/verify-options', { email: EMAIL }).then(async (res) => {
    if (res.status !== 200) return { status: res.status, raw: res.body, options: null };
    return { status: res.status, options: JSON.parse(res.body) };
  }), EMAIL);
  step('C1 passkeys-verify-options', pkOpts.status === 200 && !!pkOpts.options?.challenge, `status=${pkOpts.status} allowCred=${pkOpts.options?.allowCredentials?.length}`);
  writeFileSync(`${OUT_DIR}/passkeys-verify-options.json`, JSON.stringify(pkOpts.options, null, 2));

  const pkAssertion = await page.evaluate(async (opts) => {
    const pk = { ...opts };
    pk.challenge = window.b64ToU8(pk.challenge);
    for (const key of ['excludeCredentials', 'allowCredentials']) {
      if (key in pk) pk[key] = pk[key].map((d) => ({ ...d, id: window.b64ToU8(d.id) }));
    }
    const cred = await navigator.credentials.get({ publicKey: pk });
    const uh = cred.response.userHandle;
    const debug = {
      uhType: uh ? uh.constructor?.name : 'null',
      uhByteLen: uh ? new Uint8Array(uh).length : 0,
      uhBytesHead: uh ? Array.from(new Uint8Array(uh).slice(0, 8)) : [],
    };
    window.__uhDebug = debug;
    return {
      id: cred.id,
      type: cred.type,
      rawId: window.u8ToB64(new Uint8Array(cred.rawId)),
      response: {
        clientDataJSON: window.u8ToB64(new Uint8Array(cred.response.clientDataJSON)),
        authenticatorData: window.u8ToB64(new Uint8Array(cred.response.authenticatorData)),
        signature: window.u8ToB64(new Uint8Array(cred.response.signature)),
        userHandle: uh ? window.u8ToB64(new Uint8Array(uh)) : null,
      },
      __debug: debug,
    };
  }, pkOpts.options).catch((e) => ({ error: String(e) }));

  step('C2 browser-assertion-against-converted', !pkAssertion.error, pkAssertion.error ?? `id=${pkAssertion.id?.slice(0, 16)}...`);
  writeFileSync(`${OUT_DIR}/passkeys-assertion.json`, JSON.stringify(pkAssertion, null, 2));
  if (pkAssertion.error) throw new Error('assertion vs converted failed: ' + pkAssertion.error);

  const pkVerify = await page.evaluate((cred) => window.apiPost('/spike/verify', { credential: cred }), pkAssertion);
  let pkVerifyBody = null;
  try { pkVerifyBody = JSON.parse(pkVerify.body); } catch { /* raw */ }
  step('C3 R2-GATE VerifyPasskey-verifies-converted-credential', pkVerify.status === 200 && pkVerifyBody?.verified === true,
    `status=${pkVerify.status} body=${JSON.stringify(pkVerifyBody ?? pkVerify.body.slice(0, 200))}`);

  // ============ C4. cross-user rejection (C1 pre-proof) ============
  // Create a second user, sign a FRESH assertion with user 1's credential,
  // then ask the spike route to verify "as" user 2. The passkey row belongs
  // to user 1 -> ensurePasskeyBelongsToUser must reject.
  const clearThrottle = await page.evaluate(() => window.apiPost('/spike/clear-cache'));
  const reg2 = await page.evaluate(({ EMAIL2, PASSWORD }) => window.apiPost('/user', {
    name: 'Spike Second', email: EMAIL2, password: PASSWORD, password_confirmation: PASSWORD,
  }), { EMAIL2: EMAIL.replace('@', '+2@'), PASSWORD });
  step('C4a second-user-created', reg2.status === 200 || reg2.status === 201, `status=${reg2.status}`);

  const otherUserId = await page.evaluate((email) => window.apiPost('/spike/user-id', { email }).then(async (res) => JSON.parse(res.body).user_id), EMAIL.replace('@', '+2@'));
  const crossOpts = await page.evaluate((EMAIL) => window.apiPost('/spike/verify-options', { email: EMAIL }).then(async (res) => JSON.parse(res.body)), EMAIL);
  const crossAssertion = await page.evaluate(async (opts) => {
    const pk = { ...opts };
    pk.challenge = window.b64ToU8(pk.challenge);
    for (const key of ['excludeCredentials', 'allowCredentials']) {
      if (key in pk) pk[key] = pk[key].map((d) => ({ ...d, id: window.b64ToU8(d.id) }));
    }
    const cred = await navigator.credentials.get({ publicKey: pk });
    return {
      id: cred.id, type: cred.type,
      rawId: window.u8ToB64(new Uint8Array(cred.rawId)),
      response: {
        clientDataJSON: window.u8ToB64(new Uint8Array(cred.response.clientDataJSON)),
        authenticatorData: window.u8ToB64(new Uint8Array(cred.response.authenticatorData)),
        signature: window.u8ToB64(new Uint8Array(cred.response.signature)),
        userHandle: cred.response.userHandle ? window.u8ToB64(new Uint8Array(cred.response.userHandle)) : null,
      },
    };
  }, crossOpts).catch((e) => ({ error: String(e) }));
  const crossUser = await page.evaluate(({ cred, otherId }) => window.apiPost('/spike/verify', { credential: cred, as_user_id: otherId }), { cred: crossAssertion, otherId: otherUserId });
  let crossBody = null;
  try { crossBody = JSON.parse(crossUser.body); } catch { /* raw */ }
  step('C4b cross-user-assertion-rejected', crossUser.status !== 200,
    `status=${crossUser.status} body=${JSON.stringify(crossBody ?? crossUser.body.slice(0, 150))}`);

  // ============ D. fresh passkeys registration via package actions (R3) ============
  // C4a's register auto-logged-in user 2: restore user 1's session first.
  const reLogin = await page.evaluate(({ EMAIL, PASSWORD }) => window.apiPost('/user/login', { email: EMAIL, password: PASSWORD }), { EMAIL, PASSWORD });
  step('D-prep re-login-user-1', reLogin.status === 200, `status=${reLogin.status}`);

  const whoami = await page.evaluate(() => window.apiPost('/spike/whoami'));

  // excludeCredentials now contains the ETL'd credential the virtual
  // authenticator still holds -> the browser would rightly refuse to
  // re-register it (InvalidStateError). Clear converted rows so phase D
  // proves the FRESH-registration path independently (C evidence stands).
  await page.evaluate(() => window.apiPost('/spike/clear-passkeys'));
  step('D0 session-auth-alive', whoami.status === 200 && JSON.parse(whoami.body).auth_check === true, whoami.body.slice(0, 120));

  const freshOpts = await page.evaluate(() => window.apiPost('/spike/register-options').then(async (res) => ({ status: res.status, options: res.status === 200 ? JSON.parse(res.body) : null })));
  step('D1 fresh-register-options', freshOpts.status === 200 && !!freshOpts.options?.challenge, `status=${freshOpts.status} residentKey=${freshOpts.options?.authenticatorSelection?.residentKey}`);
  writeFileSync(`${OUT_DIR}/passkeys-register-options.json`, JSON.stringify(freshOpts.options, null, 2));

  const freshAttestation = await page.evaluate(async (opts) => {
    const pk = { ...opts };
    pk.challenge = window.b64ToU8(pk.challenge);
    if (pk.user) pk.user = { ...pk.user, id: window.b64ToU8(pk.user.id) };
    for (const key of ['excludeCredentials', 'allowCredentials']) {
      if (key in pk) pk[key] = pk[key].map((d) => ({ ...d, id: window.b64ToU8(d.id) }));
    }
    const cred = await navigator.credentials.create({ publicKey: pk });
    return {
      id: cred.id, type: cred.type,
      rawId: window.u8ToB64(new Uint8Array(cred.rawId)),
      response: {
        clientDataJSON: window.u8ToB64(new Uint8Array(cred.response.clientDataJSON)),
        attestationObject: window.u8ToB64(new Uint8Array(cred.response.attestationObject)),
      },
    };
  }, freshOpts.options).catch((e) => ({ error: String(e) }));
  step('D2 fresh-attestation-created', !freshAttestation.error, freshAttestation.error ?? '');

  const freshSave = await page.evaluate(({ cred }) => window.apiPost('/spike/register', { credential: cred, name: 'fresh-spike-device' }), { cred: freshAttestation });
  let freshSaveBody = null;
  try { freshSaveBody = JSON.parse(freshSave.body); } catch { /* raw */ }
  step('D3 StorePasskey-saves', freshSave.status === 200 && !!freshSaveBody?.id,
    `status=${freshSave.status} body=${JSON.stringify(freshSaveBody ?? freshSave.body.slice(0, 150))}`);

  // Verify the FRESH credential too (proves the derived user handle path).
  const freshVerifyOpts = await page.evaluate((EMAIL) => window.apiPost('/spike/verify-options', { email: EMAIL }).then(async (res) => JSON.parse(res.body)), EMAIL);
  const freshAssertion = await page.evaluate(async (opts) => {
    const pk = { ...opts };
    pk.challenge = window.b64ToU8(pk.challenge);
    for (const key of ['excludeCredentials', 'allowCredentials']) {
      if (key in pk) pk[key] = pk[key].map((d) => ({ ...d, id: window.b64ToU8(d.id) }));
    }
    const cred = await navigator.credentials.get({ publicKey: pk });
    return {
      id: cred.id, type: cred.type,
      rawId: window.u8ToB64(new Uint8Array(cred.rawId)),
      response: {
        clientDataJSON: window.u8ToB64(new Uint8Array(cred.response.clientDataJSON)),
        authenticatorData: window.u8ToB64(new Uint8Array(cred.response.authenticatorData)),
        signature: window.u8ToB64(new Uint8Array(cred.response.signature)),
        userHandle: cred.response.userHandle ? window.u8ToB64(new Uint8Array(cred.response.userHandle)) : null,
      },
    };
  }, freshVerifyOpts).catch((e) => ({ error: String(e) }));
  const freshVerify = await page.evaluate((cred) => window.apiPost('/spike/verify', { credential: cred }), freshAssertion);
  let freshVerifyBody = null;
  try { freshVerifyBody = JSON.parse(freshVerify.body); } catch { /* raw */ }
  step('D4 fresh-passkey-verifies', freshVerify.status === 200 && freshVerifyBody?.verified === true,
    `status=${freshVerify.status} body=${JSON.stringify(freshVerifyBody ?? freshVerify.body.slice(0, 150))}`);

  // ============ summary ============
  const failed = results.filter((r) => !r.ok);
  console.log(`\n=== CEREMONY SUMMARY: ${results.length - failed.length}/${results.length} steps passed ===`);
  writeFileSync(`${OUT_DIR}/ceremony-results.json`, JSON.stringify(results, null, 2));
  if (failed.length > 0) {
    console.log('FAILED STEPS:', failed.map((f) => f.step).join(', '));
    process.exitCode = 1;
  }
} finally {
  await browser.close();
}
