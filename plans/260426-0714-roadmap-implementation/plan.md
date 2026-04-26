---
name: Roadmap implementation
status: active
created: 2026-04-26
updated: 2026-04-26
blockedBy: []
blocks: []
source: docs/reference/roadmap.md
---

# Roadmap Implementation Plan

## Goal
Implement 2FA-Vault toward a trustworthy self-hostable 1.0 using `docs/reference/roadmap.md` as the source of truth.

## Current decision
Roadmap is newer than architecture/PDR docs. Treat roadmap as authoritative where docs disagree.

## Phase map

| Phase | Status | Purpose | Phase file |
|---|---|---|---|
| 0 | Ready | Fix shipped stubs and false claims | [phase-00-fix-existing-stubs.md](phase-00-fix-existing-stubs.md) |
| 1 | Blocked pending extension repo decision | Align browser extension with E2EE | [phase-01-extension-e2ee-sync.md](phase-01-extension-e2ee-sync.md) |
| 2 | Blocked by Phase 1 | Make extension daily-driver usable | [phase-02-extension-daily-driver.md](phase-02-extension-daily-driver.md) |
| 3 | Blocked by Phase 0 | Data-loss prevention and security basics | [phase-03-data-loss-security.md](phase-03-data-loss-security.md) |
| 4 | Blocked by Phase 3 | Account hygiene features | [phase-04-account-hygiene.md](phase-04-account-hygiene.md) |
| 5 | Blocked by Phases 1, 3, 4 | Team access workflows | [phase-05-team-workflows.md](phase-05-team-workflows.md) |
| 6 | Blocked by Phases 2, 5 | Polish and contributor readiness | [phase-06-polish-contributor-ready.md](phase-06-polish-contributor-ready.md) |

## Dependency graph

- Phase 0 → Phase 1
- Phase 0 → Phase 3
- Phase 1 → Phase 2
- Phase 1 → Phase 5
- Phase 3 → Phase 4
- Phases 1, 3, 4 → Phase 5
- Phases 2, 5 → Phase 6

## First implementation slice
Start with Phase 0:
1. Fix `public/sw.js` dynamic import/runtime issue.
2. Replace service worker hardcoded OTP with real TOTP.
3. Add HTTP E2EE round-trip tests.
4. Correct false capability claims in `CLAUDE.md` and related docs.

## Cross-repo policy
Before finishing each slice, check affected sibling repos:
- `D:/2FA-Vault/2FA-Vault-WebExtension`
- `D:/2FA-Vault/2FA-Vault-Components`
- `D:/2FA-Vault/2FA-Vault-Docs`
- `D:/2FA-Vault/2FA-Vault-API`

Update each affected repo separately with its own commit. If skipped, record why.

## Key unresolved questions
1. Which extension repo is canonical for implementation: `browser-extension/` in this repo or `D:/2FA-Vault/2FA-Vault-WebExtension`?
2. Should extension local data support a PBKDF2-to-Argon2id migration window?
3. Should `/api/v1/twofaccounts/encrypted` accept session auth, PAT auth, or both?
4. Which extension E2E stack should be used for Phase 1/2?

## Validation baseline
- `composer test` for backend/API changes.
- Targeted PHPUnit files for modified controllers/services.
- `npm run build` for frontend/service worker changes.
- Browser/service-worker smoke test for PWA changes.
- Extension E2E tests once Phase 1 begins.
- GitNexus impact before symbol edits and detect changes before commit.
