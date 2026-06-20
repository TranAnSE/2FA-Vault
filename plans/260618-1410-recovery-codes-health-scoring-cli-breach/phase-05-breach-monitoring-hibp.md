# Phase 5 — Breach Monitoring (HIBP)

## Context Links

- Plan overview: [plan.md](plan.md)
- Brainstorm: [brainstorm-report.md](brainstorm-report.md) (Feature 4)
- Research: [research/researcher-01-recovery-codes-hibp.md](research/researcher-01-recovery-codes-hibp.md)
  (HIBP v3 API, k-anonymity, caching, rate limits)
- Reference: `app/Services/SecureNoteService.php` (service shape), `app/Api/v1/Controllers/`
  (controller convention), `config/services.php` (add HIBP key), user preferences pattern
  (`UserController@setPreference`, `routes/api/v1.php` lines 55-57).

## Overview

- **Priority:** P2
- **Status:** pending
- **Effort:** 3d
- **Description:** Check user emails and service names against HaveIBeenPwned (HIBP v3). New
  `BreachMonitoringService` + `BreachController` + endpoints. Opt-in via a user preference
  (`breachMonitoring`, default false). Laravel HTTP client with 24h cache. Email checks require
  explicit opt-in; service breach lookup uses the public breach list (no PII sent).

## Key Insights

- Two check types: (1) email breach via authenticated `GET /breachedaccount/{account}` (needs
  `hibp-api-key`); (2) service breach via public `GET /breaches?domain=` / breach list (no key, no PII).
- Privacy: email check sends the user's email to HIBP — therefore strictly gated behind the
  `breachMonitoring` opt-in preference. Service check sends only a public service/domain name.
- Caching is mandatory (HIBP ToS + free-tier limits): cache breach list 24h, cache per-email result
  24h. Handle `429` with `Retry-After` (Laravel HTTP client `retry()` / backoff).
- No `twofaccounts` migration needed. `breachMonitoring` is a user preference (preferences are JSON
  on the user model — confirm during scout; if columnar, no migration but add to allowed keys).
- Follow `app/Api/v1/Controllers/` convention; register routes in the `auth:api-guard` group.
- Research flagged k-anonymity is for the *password* API; breach-by-email is a different endpoint and
  does send the email — so opt-in gating is the privacy control here, not k-anonymity.

## Requirements

**Functional**
- `POST /api/v1/breach/check-email` (body: email or "use my account email") → breach summary;
  HARD-gated by `breachMonitoring` preference (403/409 if disabled).
- `GET /api/v1/breach/check-service?service=github` → whether the service appears in HIBP breaches
  (public data, no opt-in required).
- `breachMonitoring` user preference (default false) toggled via existing preferences endpoint.
- Frontend: `BreachBadge.vue` (warning icon when breached); "Check for breaches" button on account
  detail (service check); settings toggle for email monitoring; notification if email breached.

**Non-functional**
- 24h cache TTL; graceful degradation when HIBP unreachable (return "unknown", not error spam).
- Respect rate limits (429 + Retry-After, exponential backoff).
- No HIBP key committed; read from env via `config/services.php`.

## Architecture

```
BreachController
  ├─ checkEmail(): assert preferences['breachMonitoring'] -> BreachMonitoringService::checkEmail()
  └─ checkService(): BreachMonitoringService::checkService()
BreachMonitoringService (Laravel Http client + Cache::remember 24h)
  ├─ checkEmail(email): GET breachedaccount/{email} w/ hibp-api-key  (cache key by email hash)
  └─ checkService(name): GET breaches (cache 24h) -> filter by name/domain
config/services.php: 'hibp' => ['key' => env('HIBP_API_KEY')]
```

## Related Code Files

**Create**
- `app/Services/BreachMonitoringService.php` — `checkEmail(string $email): array`,
  `checkService(string $serviceName): array`; uses `Http::withHeaders([...])->retry()` + `Cache::remember`.
- `app/Api/v1/Controllers/BreachController.php` — `checkEmail(Request)`, `checkService(Request)`.
- `app/Api/v1/Requests/BreachCheckEmailRequest.php` — validate email; enforce opt-in in controller.
- `resources/js/components/BreachBadge.vue`.
- `resources/js/services/breachService.js` — calls the two endpoints.
- `tests/Unit/Services/BreachMonitoringServiceTest.php` — `Http::fake`, cache behavior.
- `tests/Api/v1/BreachTest.php` — opt-in gate, service check, mocked HIBP.

**Modify**
- `config/services.php` — add `'hibp' => ['key' => env('HIBP_API_KEY')]`.
- `.env.example` — add `HIBP_API_KEY=`.
- `routes/api/v1.php` — inside `auth:api-guard` group:
  `Route::post('breach/check-email', [BreachController::class,'checkEmail'])->name('breach.checkEmail');`
  `Route::get('breach/check-service', [BreachController::class,'checkService'])->name('breach.checkService');`
- User preferences allowed-keys list — add `breachMonitoring` (default false). Confirm location
  during scout (preferences config/model accessor).
- Account detail view — "Check for breaches" button + `BreachBadge`.
- User settings view — "Enable breach monitoring" toggle.

**Cross-repo**
- `2FA-Vault-API/` — document both breach endpoints + schemas; `npm run validate:openapi`.
- `2FA-Vault-Docs/` — breach monitoring feature + privacy/opt-in explanation; `npm run validate:docs`.

## Implementation Steps

1. Scout user preferences mechanism; add `breachMonitoring` (default false) to allowed keys.
2. `config/services.php` + `.env.example`: HIBP key.
3. `BreachMonitoringService`: `checkService` (public breaches, 24h cache) and `checkEmail`
   (authenticated, 24h cache, 429 retry, opt-in assumed enforced upstream).
4. Unit-test service with `Http::fake` (breached, not-breached, 429, cache hit avoids 2nd call).
5. `BreachController` + request; `checkEmail` returns 403/409 when preference disabled.
6. Feature-test: opt-in gate, service check no-gate, mocked responses.
7. Frontend: badge, service-check button on detail, settings toggle, breach notification.
8. Update OpenAPI + docs (privacy note: email is sent to HIBP only with opt-in).

## Todo List

- [ ] breachMonitoring preference (default false)
- [ ] config/services.php + .env.example HIBP key
- [ ] BreachMonitoringService (checkEmail/checkService + cache + retry)
- [ ] Service unit tests (Http::fake, cache, 429)
- [ ] BreachController + request + opt-in gate
- [ ] Feature tests pass
- [ ] Frontend badge + button + settings toggle + notification
- [ ] OpenAPI + docs updated

## Success Criteria

- `check-email` returns 403/409 when `breachMonitoring` is off; works when on.
- `check-service?service=github` returns breach status without requiring opt-in.
- Second identical check within TTL served from cache (no second HIBP call — assert via `Http::fake`).
- 429 handled with backoff; HIBP outage degrades to "unknown" gracefully.
- `composer test` + `npm run lint` green; OpenAPI validates.

## Risk Assessment

- **Risk:** Sending user email to a third party. **Mitigation:** strict opt-in gate + clear UI/docs.
- **Risk:** HIBP rate limits / cost. **Mitigation:** 24h cache, backoff, per-email cache key.
- **Risk:** Missing API key in deploy. **Mitigation:** service degrades to "unknown" + admin log;
  service-check (public) still works without key only if endpoint allows — verify, else gate both.
- **Risk:** Cache leaks email in cache key. **Mitigation:** hash email for the cache key.

## Security Considerations

- Email leaves the system only with explicit `breachMonitoring` opt-in.
- HIBP key in env only; never logged or returned to client.
- Cache keys hashed; no plaintext email in cache store.
- HTTPS enforced for all HIBP calls.

## Next Steps

- Optional follow-up: scheduled daily background email re-check + push notification (out of scope v1).
- Can run in parallel with Phase 4 (no shared files).
