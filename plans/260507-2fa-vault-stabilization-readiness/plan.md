---
title: 2FA-Vault Stabilization and Release Readiness
description: >-
  Stabilize the 2FA-Vault multi-repo ecosystem until build, tests, API contract,
  docs, and release gates are credible.
status: pending
priority: P1
effort: 10-15d
branch: master
tags:
  - stabilization
  - security
  - e2ee
  - api
  - frontend
  - docs
  - infra
blockedBy: []
blocks:
  - 260426-0714-roadmap-implementation
created: '2026-05-07T10:23:01.956Z'
createdBy: 'ck:plan'
source: skill
---

# 2FA-Vault Stabilization and Release Readiness

## Overview

This plan turns the previous readiness assessment into an executable stabilization track across:

- `D:/2FA-Vault/2FA-Vault`
- `D:/2FA-Vault/2FA-Vault-API`
- `D:/2FA-Vault/2FA-Vault-Components`
- `D:/2FA-Vault/2FA-Vault-Docs`
- `D:/2FA-Vault/2FA-Vault-WebExtension`

Current baseline: main app build passes, backend PHPUnit passes, extension build passes, E2E smoke has one test-harness failure, components typecheck cannot run because workspace dependencies are incomplete, API/docs still look mostly upstream 2FA-Vault.

Goal: make the fork shippable as a clear beta/1.0 candidate with honest docs, repeatable CI, stable E2EE contract, and clean cross-repo boundaries.

## Execution Strategy

Phase 1 must run first. Phase 2 must restore verification. Phases 3-7 can run in parallel after Phase 2 if file ownership stays separate. Phase 8 runs last.

| Track | Can Run In Parallel | Primary Repo | Ownership |
|---|---|---|---|
| Phase 3 | Yes after Phase 2 | `2FA-Vault` | Laravel/Vue E2EE, teams, backup, security tests |
| Phase 4 | Yes after Phase 2 | `2FA-Vault-WebExtension` | WXT extension runtime, local OTP, extension tests |
| Phase 5 | Yes after Phase 2 | `2FA-Vault-API` | OpenAPI schemas and API changelog |
| Phase 6 | Yes after Phase 2 | `2FA-Vault-Components` | package workspace, typecheck/test/build |
| Phase 7 | Yes after Phase 2 | `2FA-Vault-Docs` and `2FA-Vault/docs` | user/admin/dev docs |

Conflict rule: one phase owns a file. If a cross-repo contract requires touching another phase's file, record it in that phase's checklist rather than editing across ownership.

## Phases

| Phase | Name | Status |
|-------|------|--------|
| 1 | [Baseline Audit](./phase-01-baseline-audit.md) | Completed |
| 2 | [Test and CI Recovery](./phase-02-test-and-ci-recovery.md) | Completed |
| 3 | [Core App E2EE Hardening](./phase-03-core-app-e2ee-hardening.md) | Pending |
| 4 | [WebExtension E2EE Stabilization](./phase-04-webextension-e2ee-stabilization.md) | Pending |
| 5 | [API Contract Alignment](./phase-05-api-contract-alignment.md) | Pending |
| 6 | [Components Workspace Hardening](./phase-06-components-workspace-hardening.md) | Pending |
| 7 | [Docs Alignment](./phase-07-docs-alignment.md) | Pending |
| 8 | [Release Readiness](./phase-08-release-readiness.md) | Pending |

## Dependencies

- Blocks existing roadmap plan: `plans/260426-0714-roadmap-implementation`
- Phase 1 blocks all phases.
- Phase 2 blocks Phases 3-7.
- Phases 3-7 block Phase 8.

## Validation Gates

- `D:/2FA-Vault/2FA-Vault`: `composer test`, `npm run build`, `npm run test:e2e:smoke`, targeted E2E for zero-knowledge and extension.
- `D:/2FA-Vault/2FA-Vault-WebExtension`: `npm run build`, Firefox build, extension E2E via app repo or repo-local Playwright.
- `D:/2FA-Vault/2FA-Vault-Components`: `pnpm install`, `pnpm run typecheck`, `pnpm run test`, `pnpm run build`.
- `D:/2FA-Vault/2FA-Vault-API`: OpenAPI validation and schema diff against implemented routes.
- `D:/2FA-Vault/2FA-Vault-Docs`: docs build, link check where available.
- Before symbol edits in `2FA-Vault`, run GitNexus impact. Before commit, run GitNexus detect changes.

## Known Issues From Assessment

- Main E2E smoke fails because `tests/e2e/encryption.spec.ts` references missing page object field `passwordInputs`.
- Components typecheck fails because package-local dependencies are not installed/resolved.
- API README/changelog still upstream-oriented and does not fully document Vault endpoints.
- Docs still largely describe 2FA-Vault, not the fork's real feature state.
- Extension build passes with warnings: duplicate `toRef`, deprecated Sass imports, browser-externalized `path/fs` via `argon2-browser`.
- Main app build passes with chunk-size and Sass deprecation warnings.
- Runtime status of E2EE, teams, backup, and extension sync needs end-to-end confirmation.

## Non-Goals

- Do not redesign crypto primitives unless audit finds a concrete flaw.
- Do not publish public packages unless required for local workflow stability.
- Do not claim production readiness until all release gates pass.
