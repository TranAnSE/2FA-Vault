# Phase 2 — Account Health Scoring (Server Metadata)

## Context Links

- Plan overview: [plan.md](plan.md)
- Brainstorm: [brainstorm-report.md](brainstorm-report.md) (Feature 2, server-side table)
- Reference: `app/Services/SecureNoteService.php` (service shape), `app/Api/v1/Controllers/TwoFAccountController.php`
  (`otp` action + policy usage), `app/Policies/TwoFAccountPolicy.php` (reuse `view`).
- Model fields used: `algorithm`, `digits`, `period`, `last_used_at` (`TwoFAccount.php`).

## Overview

- **Priority:** P2
- **Status:** pending
- **Effort:** 3.5d
- **Description:** Compute a per-account security health score from server-visible metadata only
  (algorithm, digits, freshness, period). New `AccountHealthService` + API endpoints + frontend
  badge + dashboard. Works with E2EE on (no plaintext needed). Client-side secret checks come in
  Phase 3 and merge into a combined score.

## Key Insights

- All inputs are server-visible even under E2EE: `algorithm`, `digits`, `period`, `last_used_at`.
  `secret` is NOT used here.
- Freshness uses `last_used_at` (cast to datetime, model line 171). `null` means never used.
- Reuse `TwoFAccountPolicy::view()` for the single-account endpoint; the summary endpoint scopes to
  `Auth::user()` accounts (no extra policy needed beyond auth guard).
- Follow `app/Api/v1/Controllers/` convention (not `app/Http/Controllers/`) for consistency with
  `TwoFAccountController`. Register routes in the existing `auth:api-guard` group.
- Keep `AccountHealthService` a pure function over a model — no DB writes, trivially unit-testable.

## Requirements

**Functional**
- `GET /api/v1/twofaccounts/{id}/health` → `{algorithm_score, digits_score, freshness_score,
  period_score, server_total, grade}`.
- `GET /api/v1/twofaccounts/health/summary` → aggregate for all the user's accounts: counts per
  grade, average server_total, list of weak account ids (grade C or below).
- Frontend: letter-grade badge (A–F, color-coded) on account list items + detail; a `/health`
  dashboard view with a table and a "show weak only" filter.

**Non-functional**
- Pure computation, no persistence. Summary must avoid N+1 (single query, iterate in memory).
- Scores are integers 0–100; weighted `server_total` rounded to nearest int.

## Architecture

Scoring (weights): `algorithm 30%`, `digits 20%`, `freshness 30%`, `period 20%`.

```
algorithm_score: md5=0, sha1=50, sha256=100, sha512=100
digits_score:    4=50, 5=75, 6=100, 7+=100
freshness_score: null=50, >180d=60, >30d=80, <=30d=100
period_score:    period<15=50, period>=15=100  (HOTP has no period -> treat as 100)
server_total = round(0.30*alg + 0.20*digits + 0.30*fresh + 0.20*period)
grade:        A>=90, B 75-89, C 60-74, D 40-59, F<40
```

Flow: Controller resolves account → `authorize('view', $account)` →
`AccountHealthService::computeServerScore($account)` → JSON. Summary: fetch user's accounts,
map through `computeServerScore`, aggregate.

## Related Code Files

**Create**
- `app/Services/AccountHealthService.php` — `computeServerScore(TwoFAccount $account): array`,
  plus private grade/weight helpers. Keep < 200 lines.
- `app/Api/v1/Controllers/AccountHealthController.php` — `show($id)` and `summary()`.
- `resources/js/composables/useAccountHealth.js` — fetch + cache `{id}/health` and summary.
- `resources/js/components/AccountHealthBadge.vue` — letter grade with color.
- `resources/js/views/AccountHealthDashboardView.vue` — table + "show weak only" filter.
- `tests/Unit/Services/AccountHealthServiceTest.php` — table-driven score cases.
- `tests/Api/v1/AccountHealthTest.php` — endpoint + policy + summary.

**Modify**
- `routes/api/v1.php` — inside `auth:api-guard` group, BEFORE `apiResource('twofaccounts', ...)`
  to avoid route capture:
  `Route::get('twofaccounts/health/summary', [AccountHealthController::class, 'summary'])`
  and `Route::get('twofaccounts/{id}/health', [AccountHealthController::class, 'show'])->where('id','[0-9]+')`.
- `resources/js/router/` — add `/health` route → dashboard view.
- Account list item + detail components — render `AccountHealthBadge`.

**Cross-repo**
- `2FA-Vault-API/` — document both health endpoints + response schema; `npm run validate:openapi`.
- `2FA-Vault-Docs/` — add a "Vault health" section (user-visible feature).
- `2FA-Vault-Components/` — optional: if the badge belongs in the shared library, add there and rebuild dist.

## Implementation Steps

1. Implement `AccountHealthService::computeServerScore` with the scoring table above; add grade mapping.
2. Unit-test the service with boundary inputs (md5/sha512, digits 4/6/8, freshness null/200d/15d/now,
   period 10/30, HOTP no-period).
3. Create `AccountHealthController` (`show`, `summary`); `show` calls `authorize('view',$account)`.
4. Register routes ordered so `health/summary` and `{id}/health` resolve before the apiResource.
5. Feature-test endpoints: ownership 403, correct scores, summary aggregation, weak-list contents.
6. Frontend: `useAccountHealth` composable; `AccountHealthBadge`; wire into list + detail.
7. Build `AccountHealthDashboardView` with table + "show weak only (C or below)" filter; add router entry.
8. Update OpenAPI + docs.

## Todo List

- [ ] AccountHealthService implemented
- [ ] Service unit tests (boundaries) pass
- [ ] Controller + 2 routes registered (ordering correct)
- [ ] Feature tests (policy, scores, summary) pass
- [ ] Composable + badge component
- [ ] Dashboard view + router entry + "show weak only" filter
- [ ] OpenAPI + docs updated

## Success Criteria

- Endpoint returns documented keys; grades match the mapping at boundaries.
- Summary aggregates per-grade counts and weak ids without N+1.
- Accessing another user's account health → 403.
- `composer test` + `npm run lint` green.

## Risk Assessment

- **Risk:** Route `{id}/health` captured by `apiResource` show. **Mitigation:** register specific
  routes first with `->where('id','[0-9]+')`, mirroring existing `{id}/otp`.
- **Risk:** Scoring weights become contentious. **Mitigation:** centralize constants in the service
  for easy tuning; cover with tests.
- **Risk:** Summary slow for large vaults. **Mitigation:** single select of needed columns only.

## Security Considerations

- No secrets touched; safe under E2EE.
- Authorization via `TwoFAccountPolicy::view()` (single) and auth-scoped query (summary).
- Do not leak other users' account metadata in summary aggregation.

## Next Steps

- Phase 3 extends `useAccountHealth` + dashboard to merge client-side entropy/duplicate scores.
- Coordinate any shared `TwoFAccountStoreResource` edits with Phase 1 if parallel.
