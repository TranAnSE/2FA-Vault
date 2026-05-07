---
phase: 4
title: "WebExtension E2EE Stabilization"
status: pending
priority: P1
effort: "2d"
dependencies: [2]
---

# Phase 4: WebExtension E2EE Stabilization

## Overview

Make the external WebExtension reliable against the app's encrypted vault API and local OTP generation model.

## Requirements

- Functional: extension can bind, unlock encrypted vault, list accounts, generate OTP locally, lock/clear session state, and build for Chrome/Firefox.
- Non-functional: no plaintext secrets persist beyond intended session state; extension contract matches app API.

## Architecture

WXT popup calls the API for encrypted account payloads, imports the derived vault key in session storage, decrypts locally, and generates OTP without using backend OTP endpoints for encrypted accounts.

## Related Code Files

- Modify: `D:/2FA-Vault/2FA-Vault-WebExtension/src/entrypoints/background.js`
- Modify: `D:/2FA-Vault/2FA-Vault-WebExtension/src/entrypoints/popup/services/e2eeCryptoService.js`
- Modify: `D:/2FA-Vault/2FA-Vault-WebExtension/src/entrypoints/popup/services/localOtpService.js`
- Modify: `D:/2FA-Vault/2FA-Vault-WebExtension/src/entrypoints/popup/services/twofaccountService.js`
- Modify: `D:/2FA-Vault/2FA-Vault-WebExtension/src/entrypoints/popup/stores/twofaccounts.js`
- Modify: `D:/2FA-Vault/2FA-Vault/tests/e2e/webextension/**` if app repo remains test owner

## Implementation Steps

1. Confirm canonical extension repo from Phase 1.
2. Fix or document build warnings: duplicate `toRef`, Sass `@import`, `argon2-browser` browser externalization.
3. Verify encrypted account endpoint usage: `/api/v1/twofaccounts/encrypted`.
4. Add/repair extension E2E for unlock, local OTP generation, lock, reset, and invalid password.
5. Verify Chrome MV3 and Firefox build outputs.
6. Ensure session storage and popup store clear decrypted data on lock/reset.
7. Document extension compatibility matrix in docs.

## Success Criteria

- [ ] `npm run build` passes for Chrome.
- [ ] `npm run build:firefox` passes.
- [ ] Extension E2E proves encrypted OTP generation does not call backend OTP endpoint.
- [ ] Lock/reset clears derived vault key and decrypted account state.
- [ ] Remaining warnings are either fixed or tracked with owner/date.

## Risk Assessment

Risk: extension and app crypto diverge. Mitigation: share test vectors and enforce API schema in Phase 5.
