---
phase: 6
title: "Components Workspace Hardening"
status: pending
priority: P2
effort: "1.5d"
dependencies: [2]
---

# Phase 6: Components Workspace Hardening

## Overview

Make the shared component workspace installable, type-checkable, testable, and usable by the app and extension without brittle manual state.

## Requirements

- Functional: PNPM workspace installs and runs all scripts; package links work for app and extension.
- Non-functional: minimal new abstraction; add tests only for shared controls/stores with real reuse risk.

## Architecture

Packages:

- `@2fauth/ui`
- `@2fauth/formcontrols`
- `@2fauth/stores`
- `@2fauth/styles`

Consumers use local `file:` or workspace links from app/extension.

## Related Code Files

- Modify: `D:/2FA-Vault/2FA-Vault-Components/package.json`
- Modify: `D:/2FA-Vault/2FA-Vault-Components/pnpm-workspace.yaml`
- Modify: `D:/2FA-Vault/2FA-Vault-Components/packages/*/package.json`
- Modify: `D:/2FA-Vault/2FA-Vault-Components/packages/**/src/**`
- Modify: `D:/2FA-Vault/2FA-Vault/package.json` only for dependency path alignment
- Modify: `D:/2FA-Vault/2FA-Vault-WebExtension/package.json` only for dependency path alignment

## Implementation Steps

1. Run `pnpm install` in components repo and commit lockfile changes if valid.
2. Fix typecheck dependency resolution (`vue-tsc`, workspace package links).
3. Run `pnpm run typecheck`, `pnpm run test`, `pnpm run build`.
4. Add missing tests for shared stores/controls touched by E2EE or extension flows.
5. Resolve circular package dependencies if they block clean install.
6. Verify app and extension still build against local component packages.

## Success Criteria

- [ ] Fresh clone can run `pnpm install` then all workspace scripts.
- [ ] Shared component packages build dist output.
- [ ] At least critical shared controls have tests.
- [ ] App and extension dependency paths are documented and consistent.

## Risk Assessment

Risk: trying to make packages publishable adds unnecessary work. Mitigation: target local workspace reliability only unless publish is required.
