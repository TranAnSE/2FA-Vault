/**
 * E2EE fixture envelope generator (plan 261119-1650 phase 2).
 *
 * Regenerates the pinned encrypted fixture pair consumed by the PWA offline
 * and WebExtension popup suites:
 *   - database/factories/UserFactory.php   (e2eEncrypted: salt + test value envelope)
 *   - database/seeders/E2eSeeder.php       (three account envelopes)
 *
 * The original pair was NEVER valid (plan research: 272-combination Argon2
 * matrix, no password ever matched) and no generator existed. This script is
 * the committed source of truth for those blobs: re-running it must produce
 * BYTE-IDENTICAL envelopes — hence the fixed IV below.
 *
 * Derivation mirrors resources/js/services/crypto.js exactly:
 *   - ARGON2_CONFIG { time: 3, mem: 65536 KiB, hashLen: 32, parallelism: 1, Argon2id }
 *   - salt base64-DECODED before hashing; the raw 32-byte hash is the vault key
 *   - encryptSecret: AES-256-GCM, 12-byte IV, tagLength 128, ciphertext||authTag split
 *
 * Runs the real argon2-browser wasm in Node via the self.Module.wasmBinary
 * preload (the browser-free variant of the fixture-gen/serve harness).
 *
 * Usage (from the repo root):
 *   node tests/e2e/helpers/regen-e2ee-vault.mjs
 *
 * FIXTURES ONLY — the fixed IV is safe ONLY here. Never use it in production
 * code paths.
 */

import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

// ------------------------------------------------------- pinned fixture inputs

const MASTER_PASSWORD = 'MasterPass123!';
const SALT_B64 = 'FYZWmoAq3pv+3nQjXhcp7MadT/EM7YrEFIgS4ruhSOo=';

// Fixed 12-byte IV ('fixed-iv!' + 0x00 0x01 0x02) so a re-run reproduces the
// blobs byte for byte. Determinism is the point; secrecy is not (fixtures).
const FIXED_IV = Uint8Array.from([
    0x66, 0x69, 0x78, 0x65, 0x64, 0x2d, 0x69, 0x76, 0x21, 0x00, 0x01, 0x02,
]);

// plaintext per envelope, matching what the specs assert:
//   user test value  -> verifyPassword() compares the decryption to this constant
//                       (resources/js/services/crypto.js)
//   account envelopes -> the seeded secrets the PWA offline spec and the popup
//                       spec decrypt and OTP against (E2eSeeder VaultDrive /
//                       Banking / VaultHOTP all carry the same secret).
const ENVELOPE_PLAINTEXTS = {
    user_test_value: 'VERIFICATION_TEST_VALUE',
    account_vaultdrive: 'A4GRFTVVRBGY7UIW',
    account_banking: 'A4GRFTVVRBGY7UIW',
    account_vaulthotp: 'A4GRFTVVRBGY7UIW',
};

// ---------------------------------------------------------------- argon2 (wasm)

// The argon2-browser bundle resolves its wasm through the emscripten Module
// object on `self`. Preload the .wasm binary so the bundle never tries to
// fetch it (there is no browser fetch layer under Node).
const self_ = /** @type {any} */ (globalThis);
self_.self = self_;

const bundlePath = require.resolve('argon2-browser/dist/argon2-bundled.min.js');
const wasmPath = path.join(path.dirname(bundlePath), 'argon2.wasm');

if (fs.existsSync(wasmPath)) {
    self_.Module = self_.Module ?? {};
    self_.Module.wasmBinary = fs.readFileSync(wasmPath);
}

// eslint-disable-next-line @typescript-eslint/no-require-imports
const argon2Bundle = require(bundlePath);
const argon2 = argon2Bundle.default ?? argon2Bundle;

if (typeof argon2?.hash !== 'function') {
    console.error('argon2-browser bundle did not expose hash(); exports:', Object.keys(argon2Bundle));
    process.exit(1);
}

// ------------------------------------------------------------------ derivation

function base64ToBytes(base64) {
    return Uint8Array.from(Buffer.from(base64, 'base64'));
}

function bytesToBase64(bytes) {
    return Buffer.from(bytes).toString('base64');
}

const ARGON2_CONFIG = {
    time: 3,
    mem: 65536,
    hashLen: 32,
    parallelism: 1,
    type: argon2.ArgonType.Argon2id,
};

/** Mirror of crypto.js encryptSecret, minus the random IV (fixed above). */
async function encryptSecretFixedIv(plaintext, key) {
    const plaintextBytes = new TextEncoder().encode(plaintext);

    const combined = await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv: FIXED_IV, tagLength: 128 },
        key,
        plaintextBytes,
    );

    const combinedBytes = new Uint8Array(combined);
    const tagLen = 16;
    const ciphertext = combinedBytes.slice(0, -tagLen);
    const authTag = combinedBytes.slice(-tagLen);

    return {
        ciphertext: bytesToBase64(ciphertext),
        iv: bytesToBase64(FIXED_IV),
        authTag: bytesToBase64(authTag),
    };
}

const derived = await argon2.hash({
    pass: MASTER_PASSWORD,
    salt: base64ToBytes(SALT_B64),
    ...ARGON2_CONFIG,
});

const rawKey = new Uint8Array(derived.hash);
const aesKey = await crypto.subtle.importKey('raw', rawKey, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);

// ------------------------------------------------------------------- emission

const envelopes = {};
for (const [name, plaintext] of Object.entries(ENVELOPE_PLAINTEXTS)) {
    envelopes[name] = await encryptSecretFixedIv(plaintext, aesKey);
}

const phpTestValue = "json_encode([\n"
    + `                    'ciphertext' => '${envelopes.user_test_value.ciphertext}',\n`
    + `                    'iv' => '${envelopes.user_test_value.iv}',\n`
    + `                    'authTag' => '${envelopes.user_test_value.authTag}',\n`
    + '                ])';

const phpAccountEnvelope = (indent) => 'json_encode([\n'
    + `${indent}    'ciphertext' => '${envelopes.account_vaultdrive.ciphertext}',\n`
    + `${indent}    'iv' => '${envelopes.account_vaultdrive.iv}',\n`
    + `${indent}    'authTag' => '${envelopes.account_vaultdrive.authTag}',\n`
    + `${indent}])`;

console.log('// ------------------------------------------------------------------');
console.log('// Generated by tests/e2e/helpers/regen-e2ee-vault.mjs');
console.log(`//   node tests/e2e/helpers/regen-e2ee-vault.mjs`);
console.log(`//   password: ${MASTER_PASSWORD} | Argon2id time=${ARGON2_CONFIG.time} mem=${ARGON2_CONFIG.mem} hashLen=${ARGON2_CONFIG.hashLen} parallelism=${ARGON2_CONFIG.parallelism}`);
console.log(`//   salt (pinned): ${SALT_B64}`);
console.log('// ------------------------------------------------------------------');
console.log('');
console.log('// UserFactory e2eEncrypted:');
console.log(`'encryption_salt' => '${SALT_B64}',`);
console.log(`'encryption_test_value' => ${phpTestValue},`);
console.log('');
console.log('// E2eSeeder account envelope (all three accounts):');
console.log(phpAccountEnvelope('            '));
