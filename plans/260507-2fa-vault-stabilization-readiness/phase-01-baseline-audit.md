---
phase: 1
title: Baseline Audit
status: completed
priority: P1
effort: 1d
dependencies: []
---

# Phase 1: Baseline Audit

## Overview

Create a clean, reproducible baseline across all five repos before touching implementation. This phase prevents chasing stale generated files, uncommitted local edits, or unclear canonical sources.

## Requirements

- Functional: identify dirty files, generated outputs, dependency state, canonical extension repo, and current failing gates.
- Non-functional: no source edits except generated audit reports and plan notes.

## Architecture

This is a coordination phase. It maps repo ownership and confirms which artifacts are source-controlled versus generated.

## Related Code Files

- Read: `D:/2FA-Vault/2FA-Vault/README.md`
- Read: `D:/2FA-Vault/2FA-Vault/docs/**`
- Read: `D:/2FA-Vault/2FA-Vault-API/README.md`
- Read: `D:/2FA-Vault/2FA-Vault-Components/package.json`
- Read: `D:/2FA-Vault/2FA-Vault-Docs/retype.yml`
- Read: `D:/2FA-Vault/2FA-Vault-WebExtension/package.json`
- Create: `D:/2FA-Vault/2FA-Vault/plans/reports/stabilization-baseline.md`

## Implementation Steps

1. Capture `git status --short --branch` for all five repos.
2. List generated/build directories and decide ignore/clean policy: `public/build`, `dist`, `.output`, `test-results`, `playwright-report`.
3. Confirm canonical browser extension path: external `2FA-Vault-WebExtension` vs embedded `2FA-Vault/browser-extension`.
4. Capture dependency state: `composer install` status, `npm ci/install` status, `pnpm install` status.
5. Record current command matrix with pass/fail and exact failure reason.
6. Run GitNexus repo context check for `2FA-Vault`; if stale, run `npx gitnexus analyze`.
7. Write baseline report in `plans/reports/stabilization-baseline.md`.

## Success Criteria

- [x] All five repos have documented status and dirty-file owner.
- [x] Canonical extension repo decision recorded.
- [x] Build/test command matrix recorded with exact current failures.
- [x] Generated artifact policy recorded.
- [x] No unrelated local/user changes reverted.

## Risk Assessment

Risk: generated build files can hide source diffs. Mitigation: record clean/dirty state before every build and decide artifact policy first.
