# Phase 2 Verification Recovery

Date: 2026-05-07
Plan: `plans/260507-2fa-vault-stabilization-readiness/plan.md`

## Summary

Phase 2 restored the verification loop for the current stabilization baseline.

## App Repo

Repo: `D:/2FA-Vault/2FA-Vault`

- Fixed E2E page-object compatibility by restoring `SetupEncryptionPage.passwordInputs` and `SetupEncryptionPage.checkbox` aliases while keeping newer named locators.
- Hardened the E2E server bootstrap to recreate `database/database_e2e.sqlite` on every run so a malformed local SQLite file cannot break startup before migrations run.
- Hardened E2E startup/teardown on Windows: startup removes stale SQLite files with retries, and teardown logs a warning instead of failing an otherwise passing run.
- Removed the stale WebExtension popup skip path; the popup project now runs after the webServer builds the extension.
- Fixed the popup encrypted local OTP assertion so it verifies the rendered TOTP against the seeded secret and confirms no backend `/otp` request.
- Added a narrow HOTP counter update endpoint so local encrypted HOTP generation can persist the next counter without revalidating or touching the encrypted secret payload.
- Hardened HOTP counter updates to be monotonic and atomic; stale or replayed counter writes now return validation errors instead of rolling the server counter backward.
- `npm run test:e2e:smoke`: pass, 17 tests.
- `npx playwright test tests/e2e/zero-knowledge.spec.ts --workers=1 --reporter=line`: pass, 4 tests.
- `npx playwright test --project=webextension-popup tests/e2e/webextension/popup-encrypted-local-otp.spec.ts --workers=1 --reporter=line`: pass, 1 test.
- `composer test`: pass, 1391 tests, 4291 assertions, 2 skipped, 16 PHPUnit doc-comment metadata deprecations.
- Review follow-up rerun after SQLite bootstrap hardening:
  - `npm run test:e2e:smoke`: pass, 17 tests.
  - `npx playwright test tests/e2e/zero-knowledge.spec.ts --workers=1 --reporter=line`: pass, 4 tests.
  - `npx playwright test --project=webextension-popup tests/e2e/webextension/popup-encrypted-local-otp.spec.ts --workers=1 --reporter=line`: pass, 1 test.
  - `composer test`: pass, 1391 tests.
- Review follow-up after HOTP monotonic counter hardening:
  - `.\vendor\bin\phpunit tests\Api\v1\Controllers\TwoFAccountControllerTest.php --filter update_counter`: pass, 3 tests, 11 assertions.
  - `npx playwright test --project=webextension-popup tests/e2e/webextension/popup-encrypted-local-otp.spec.ts --workers=1 --reporter=line`: pass, 1 test.
  - Final code review: no blocking issues remain; residual gaps are concurrent HOTP simulation, null/default counter case, and frontend negative-path coverage.
- Follow-up coverage closure:
  - Added API coverage for duplicate next-counter writes from two stale clients and null/default HOTP counters.
  - Added popup E2E coverage for local HOTP counter-sync failure routing through the error view.
  - `.\vendor\bin\phpunit tests\Api\v1\Controllers\TwoFAccountControllerTest.php --filter update_counter`: pass, 5 tests, 19 assertions.
  - `npx playwright test --project=webextension-popup tests/e2e/webextension/popup-encrypted-local-otp.spec.ts --workers=1 --reporter=line`: pass, 2 tests.
- `npm run build`: pass with known warnings:
  - Sass deprecations from Bulma.
  - Large chunk warning.
  - Mixed static/dynamic import warning for `httpClientFactory.js`.
- `composer test`: pass with issues:
  - 1391 tests.
  - 4291 assertions.
  - 2 skipped.
  - 16 PHPUnit doc-comment metadata deprecations.
  - Runtime PHP is 8.3.27 while composer requires `^8.4`.

## Components Repo

Repo: `D:/2FA-Vault/2FA-Vault-Components`

- `pnpm install`: completed.
- `pnpm run typecheck`: pass.
- `pnpm run test`: pass, 8 tests.
- `pnpm run build`: pass.
- Changes made by worker:
  - `packages/formcontrols/vite.config.ts`
  - `packages/formcontrols/vitest.setup.js`
  - `packages/formcontrols/package.json`
  - `pnpm-lock.yaml`

## API Repo

Repo: `D:/2FA-Vault/2FA-Vault-API`

- `npm run validate:openapi`: pass.
- All 10 OpenAPI specs valid.
- Remaining warnings: 156 Redocly warnings.
- Known warning categories:
  - missing tag descriptions
  - invalid media type examples
  - invalid schema examples
  - missing 4xx responses
- `npm install` reported 3 moderate npm audit vulnerabilities.

## Docs Repo

Repo: `D:/2FA-Vault/2FA-Vault-Docs`

- `npm run validate:docs`: pass.
- Retype built 27 pages into `.retype-build`.
- 0 errors, 0 warnings.

## WebExtension Repo

Repo: `D:/2FA-Vault/2FA-Vault-WebExtension`

- `npm run build`: pass for Chrome MV3.
- `npm run build:firefox`: pass for Firefox MV2.
- Fixed popup E2EE Argon2 loading by using the bundled `argon2-browser` build that works inside the packaged extension.
- Fixed encrypted popup OTP routing so the modal uses the local OTP service after vault unlock instead of retaining the backend service captured at mount.
- Normalized Web Crypto HMAC algorithm names (`sha1` -> `SHA-1`, etc.) so browser-side TOTP/HOTP generation succeeds.
- Local HOTP counter persistence now uses explicit error-return behavior so failed counter updates reject instead of hanging on the shared HTTP interceptor.
- Local OTP copy/renew paths now report rejected OTP generation or counter-sync errors through the popup error handler instead of leaving unhandled promises.
- Remaining warnings:
  - duplicate `toRef` auto-import warnings
  - Sass `@import` deprecation
  - dynamic/static import chunk warning for popup router
  - Firefox build skips `offscreen` entrypoint

## Remaining Non-Blocking Work

- Clean up OpenAPI warnings in Phase 5.
- Clean up WebExtension build warnings in Phase 4.
- Decide whether PHP 8.4 is mandatory for local CI now.
- Decide lifecycle of embedded `2FA-Vault/browser-extension`.
