# Phase 01 — Extension E2EE Sync

## Context links
- Roadmap: `docs/reference/roadmap.md`
- Main app crypto: `resources/js/services/crypto.js`
- Extension repo candidate: `D:/2FA-Vault/2FA-Vault-WebExtension`
- In-repo extension candidate: `browser-extension/`

## Overview
Priority: Critical  
Status: Blocked pending canonical extension repo decision  
Make the browser extension decrypt and use main-app E2EE vault data.

## Requirements
- Use Argon2id parameters compatible with the main app.
- Match `{ciphertext, iv, authTag}` JSON shape exactly.
- Add unlock flow in extension popup.
- Store derived key only in `chrome.storage.session` or memory-equivalent session storage.
- Add `/api/v1/twofaccounts/encrypted` endpoint returning raw encrypted account data.
- Generate OTP client-side in extension.
- Add cross-repo round-trip tests.

## Related repos/files
Main app:
- `routes/api/v1.php`
- `app/Api/v1/Controllers/TwoFAccountController.php`
- `app/Api/v1/Resources/*TwoFAccount*`
- `tests/Api/v1/`

Extension:
- crypto service files
- popup unlock/account views
- API client service
- background/session storage code

API docs:
- `D:/2FA-Vault/2FA-Vault-API`

User docs:
- `D:/2FA-Vault/2FA-Vault-Docs`

## Implementation steps
1. Ask/confirm canonical extension repo.
2. Create fixed crypto test vectors in main app and extension.
3. Add encrypted accounts endpoint as additive API.
4. Add server tests proving endpoint returns ciphertext without decryption.
5. Replace extension PBKDF2 flow with Argon2id-compatible flow.
6. Build extension unlock and auto-lock behavior.
7. Add extension-side TOTP/HOTP generation tests.
8. Add E2E flow: unlock → list encrypted accounts → generate OTP.
9. Update OpenAPI and docs.

## Success criteria
- Extension decrypts web-app encrypted accounts using shared test vectors.
- Server never returns plaintext for encrypted endpoint.
- Extension key clears on browser close/session end.
- API and docs reflect new endpoint.

## Risk assessment
- Crypto mismatch is critical risk.
- Extension storage semantics vary by browser.
- Auth mode for new endpoint must be decided before implementation.

## Security considerations
- No localStorage for derived keys.
- No server-side decryption.
- No plaintext secrets in logs, tests snapshots, or API docs examples.
