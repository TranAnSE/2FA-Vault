import { createHmac } from 'node:crypto';

/**
 * Shared TOTP/HOTP computation helpers for E2E tests.
 *
 * Used by both the browser-extension popup tests (tests/e2e/webextension) and
 * the PWA offline tests (tests/e2e/pwa) to assert that an OTP displayed in the
 * UI matches what RFC 6238 (TOTP) / 4226 (HOTP) would produce for a known
 * secret, without relying on the server's /otp endpoint.
 */

/**
 * Decode a Base32 (RFC 4648) string into a Buffer.
 * Tolerates whitespace and trailing padding.
 */
export function base32ToBuffer(encoded: string): Buffer {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const bytes: number[] = [];
  let bits = 0;
  let value = 0;

  for (const char of encoded.toUpperCase().replace(/=+$/g, '').replace(/\s+/g, '')) {
    const index = alphabet.indexOf(char);
    if (index === -1) {
      continue;
    }

    value = (value << 5) | index;
    bits += 5;

    if (bits >= 8) {
      bytes.push((value >>> (bits - 8)) & 0xff);
      bits -= 8;
    }
  }

  return Buffer.from(bytes);
}

/**
 * Compute the 6-digit TOTP for the given Base32 secret at `timestamp`
 * (default: now). Uses SHA-1, 30s period, 6 digits — the 2FA-Vault defaults.
 */
export function totpAt(secretBase32: string, timestamp: number = Date.now()): string {
  const counter = Math.floor(Math.floor(timestamp / 1000) / 30);
  return hotpAt(secretBase32, counter);
}

/**
 * Compute the 6-digit HOTP for the given Base32 secret at `counter`.
 */
export function hotpAt(secretBase32: string, counter: number): string {
  const counterBuffer = Buffer.alloc(8);
  counterBuffer.writeBigUInt64BE(BigInt(counter));

  const hmac = createHmac('sha1', base32ToBuffer(secretBase32)).update(counterBuffer).digest();
  const offset = hmac[hmac.length - 1] & 0x0f;
  const binary =
    ((hmac[offset] & 0x7f) << 24) |
    ((hmac[offset + 1] & 0xff) << 16) |
    ((hmac[offset + 2] & 0xff) << 8) |
    (hmac[offset + 3] & 0xff);

  return String(binary % 1_000_000).padStart(6, '0');
}

/**
 * Return the set of plausible TOTP values around "now": the current 30s window
 * plus two neighbours on each side. Use this in assertions when the exact
 * moment the UI generated the OTP is unknown (clock drift between the browser
 * under test and the test runner, slow DOM reads, etc.).
 */
export function totpWindow(secretBase32: string, timestamp: number = Date.now()): string[] {
  return [
    totpAt(secretBase32, timestamp - 60_000),
    totpAt(secretBase32, timestamp - 30_000),
    totpAt(secretBase32, timestamp),
    totpAt(secretBase32, timestamp + 30_000),
    totpAt(secretBase32, timestamp + 60_000),
  ];
}
