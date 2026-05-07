---
phase: 2
title: Test and CI Recovery
status: completed
priority: P1
effort: 2d
dependencies:
  - 1
---

# Phase 2: Test and CI Recovery

## Overview

Restore a trustworthy verification loop before hardening features. Fix test harness failures, dependency install issues, and CI scripts so every later phase has a reliable gate.

## Requirements

- Functional: app smoke E2E passes, components typecheck/test/build runs, extension build warnings are tracked, API/docs validation scripts exist.
- Non-functional: keep fixes narrow; do not change product behavior just to satisfy a brittle test.

## Architecture

Verification layers:

1. Backend PHPUnit for Laravel behavior.
2. Vite build for web app bundles.
3. Playwright E2E for E2EE/user workflows.
4. WXT build and browser extension E2E for popup/local OTP.
5. PNPM workspace checks for shared components.
6. OpenAPI/docs validation for contract and docs.

## Related Code Files

- Modify: `D:/2FA-Vault/2FA-Vault/tests/e2e/encryption.spec.ts`
- Modify: `D:/2FA-Vault/2FA-Vault/tests/e2e/pages/SetupEncryptionPage.ts`
- Modify: `D:/2FA-Vault/2FA-Vault/package.json`
- Modify: `D:/2FA-Vault/2FA-Vault-Components/package.json`
- Modify: `D:/2FA-Vault/2FA-Vault-Components/pnpm-lock.yaml` if dependency resolution changes
- Modify: `.github/workflows/**` in each repo only if present and failing

## Implementation Steps

1. Fix `SetupEncryptionPage` mismatch: either add `passwordInputs` locator or update spec to use existing `masterPasswordInput`.
2. Run `npm run test:e2e:smoke`; then run targeted `zero-knowledge.spec.ts` and webextension popup specs.
3. Install/repair `2FA-Vault-Components` workspace dependencies with PNPM. Run typecheck/test/build.
4. Add or normalize scripts so local and CI commands match.
5. Add OpenAPI validation command for `2FA-Vault-API`.
6. Add docs build command for `2FA-Vault-Docs` using Retype.
7. Document warning budget: what must fail CI, what is allowed temporarily, and expiration date.

## Success Criteria

- [x] `composer test` passes in `2FA-Vault`.
- [x] `npm run build` passes in `2FA-Vault`.
- [x] `npm run test:e2e:smoke` passes in `2FA-Vault`.
- [x] `pnpm run typecheck`, `pnpm run test`, and `pnpm run build` run in `2FA-Vault-Components`.
- [x] `npm run build` and `npm run build:firefox` pass in `2FA-Vault-WebExtension`.
- [x] API/docs validation commands are documented and runnable.

## Risk Assessment

Risk: fixing tests can mask real defects. Mitigation: inspect screenshots/error context first; prefer page-object fix when UI behavior is correct.
