---
title: "Recovery Codes, Health Scoring, CLI & Breach Monitoring"
description: "4 security features: encrypted recovery codes storage, account health scoring, CLI tool, and HIBP breach monitoring"
status: complete
priority: P2
effort: 15d
branch: main
tags: [security, ux, cli, e2ee]
created: 2026-06-18
completed: 2026-06-20
---

# Recovery Codes, Health Scoring, CLI & Breach Monitoring

Four self-contained security/UX features for 2FA-Vault, split into 5 phases. All work respects
the zero-knowledge E2EE boundary: the server never sees plaintext secrets when E2EE is enabled.

## Phases

| # | Phase | Status | Effort | Depends On | Repos Touched |
|---|-------|--------|--------|------------|---------------|
| 1 | [Recovery Codes Storage](phase-01-recovery-codes-storage.md) | complete | 3d | — | app, API spec |
| 2 | [Health Scoring — Server Metadata](phase-02-account-health-scoring-server.md) | complete | 3.5d | — | app, API spec |
| 3 | [Health Scoring — Client Secret Checks](phase-03-account-health-scoring-client.md) | complete | 2d | Phase 2 | app |
| 4 | [CLI Tool](phase-04-cli-tool-go-binary.md) | complete (extended existing Bun CLI) | 3.5d | — | `2FA-Vault-CLI`, docs |
| 5 | [Breach Monitoring (HIBP)](phase-05-breach-monitoring-hibp.md) | complete | 3d | — | app, API spec |

> **Note:** Phase 4 originally specified a new Go binary, but `2FA-Vault-CLI/` already
> existed as a committed Bun/TypeScript CLI. To honor DRY and preserve git history,
> the existing `2fav` CLI was extended instead (added `--search`, `--watch`, `--copy`,
> E2EE detection). See the decision record in the session.

## Recommended Execution Order

1. Phase 1 and Phase 2 in parallel (independent, both touch `TwoFAccount` resource — coordinate
   the single shared `TwoFAccountReadResource` edit).
2. Phase 3 after Phase 2 (extends the same composable/dashboard).
3. Phase 4 and Phase 5 in parallel with the above (no backend coupling for Phase 4).

## Key Dependencies & Cross-Cutting Decisions

- **Controller location:** New API endpoints follow the existing `app/Api/v1/Controllers/`
  convention (NOT `app/Http/Controllers/`), to match `TwoFAccountController`, `SecureNoteController`,
  etc. Routes register inside the `auth:api-guard` + `enforceMandatoryEncryption` group in
  `routes/api/v1.php`. (Task brief said `app/Http/Controllers/`; overridden for codebase consistency.)
- **Migration sequence:** `2026_06_18_000001_...` (recovery codes). Phases 2/3/5 add no `twofaccounts`
  columns; Phase 5 adds a user preference (preference key, no migration if preferences are JSON).
- **Resource edits:** Phases 1 share `TwoFAccountStoreResource` / `TwoFAccountReadResource`. Apply
  Phase 1's field first; health scores are served by a dedicated endpoint, not embedded in the
  account resource.
- **E2EE boundary:** Recovery codes are client-encrypted exactly like `notes`. Entropy/duplicate
  health checks (Phase 3) run client-side only. CLI (Phase 4) supports non-E2EE vaults only in v1.

## Cross-Repo Impact Summary

| Sibling | Phase 1 | Phase 2 | Phase 3 | Phase 4 | Phase 5 |
|---------|:------:|:------:|:------:|:------:|:------:|
| `2FA-Vault-API` (OpenAPI) | yes | yes | — | — | yes |
| `2FA-Vault-WebExtension` | check (account payload) | optional | — | — | — |
| `2FA-Vault-Docs` | optional | yes | — | yes | yes |
| `2FA-Vault-Components` | — | optional (badge) | — | — | — |

## Definition of Done (whole plan)

- All phases' success criteria met; `composer test` + `npm run lint` green in `2FA-Vault/`.
- `2FA-Vault-API` OpenAPI validates (`npm run validate:openapi`).
- `2FA-Vault-Docs` validates (`npm run validate:docs`) where touched.
- CLI builds for linux/macos/windows in CI.
- Docs (`docs/reference/project-changelog.md`, roadmap) updated.
