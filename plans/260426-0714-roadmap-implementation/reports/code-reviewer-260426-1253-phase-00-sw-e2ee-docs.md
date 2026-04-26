## Code Review Summary

### Scope
- Files: `public/sw.js`, `tests/Feature/AccountEncryptionE2ETest.php`, `CLAUDE.md`, `docs/architecture/project-overview-pdr.md`
- LOC: ~293 changed lines (`git diff --stat`: +210 / -83)
- Focus: Phase 00 targeted regression/security review
- Scout findings:
  - No app-side caller currently posts `SAVE_VAULT_KEY` / `SAVE_ENCRYPTED_ACCOUNTS` / `GENERATE_TOTP` messages to `public/sw.js`.
  - No `sync` event handler/replay path exists in `public/sw.js` (background sync remains unimplemented).
  - Account algorithm contract still allows `md5` (`app/Api/v1/Requests/TwoFAccountStoreRequest.php:35`, `app/Api/v1/Requests/TwoFAccountUpdateRequest.php:37`, `app/Models/TwoFAccount.php:95`).

### Overall Assessment
Phase 00 improves correctness by removing the broken dynamic import and replacing the hardcoded OTP stub with real Web Crypto TOTP logic. However, there are still high-confidence gaps: one runtime compatibility edge case (`md5`), and one test that overstates what it proves about server opacity.

### Critical Issues
- None found in scoped changes.

### High Priority
1. **`md5` algorithm path can fail at runtime in service worker**
   - **Where:** `public/sw.js:269-277`, `public/sw.js:291-293`
   - **Problem:** `normalizeHashAlgorithm()` can output `MD5`, but Web Crypto HMAC MD5 is generally unsupported. The code will throw and return an error for affected accounts.
   - **Impact:** Offline OTP generation fails for valid existing accounts configured with `md5` in backend contract.
   - **Fix:** Guard `md5` explicitly and fail fast with deterministic user-facing message (or remove `md5` from accepted algorithms across API/model if no longer supported).

2. **New E2EE round-trip assertion does not prove “server never reveals plaintext”**
   - **Where:** `tests/Feature/AccountEncryptionE2ETest.php:71-110` (especially line 103)
   - **Problem:** `assertStringNotContainsString('client_encrypted_totp_secret', $account->secret)` is weak because test input intentionally base64-encodes that plaintext into `ciphertext`; absence of raw plaintext is expected regardless.
   - **Impact:** Test may give false confidence about zero-knowledge guarantees.
   - **Fix:** Assert strict pass-through invariants instead (DB and response bytes match input exactly), and add a negative assertion against accidental server-side transformation/decode behavior.

### Medium Priority
1. **Docs still overstate current offline OTP capability**
   - **Where:** `CLAUDE.md:53`, `docs/architecture/project-overview-pdr.md:41`, `docs/architecture/project-overview-pdr.md:183`
   - **Problem:** Wording implies shipped “offline OTP support,” but scoped scout found no active app-side integration sending OTP-generation messages to the service worker.
   - **Impact:** Contributor/operator expectations can drift from actual runtime behavior.
   - **Fix:** Reword to “offline OTP primitives/prototype exist; end-to-end UX/integration is still in progress” unless a wired flow is added.

2. **PDR remains internally inconsistent on sync behavior**
   - **Where:** `docs/architecture/project-overview-pdr.md:185` vs `:187-201`
   - **Problem:** It states background sync is not implemented, but the “Data Sync” diagram still describes queued updates syncing when online.
   - **Impact:** Confuses roadmap state and can mislead implementation planning.
   - **Fix:** Mark diagram as target-state/future-state or align it to current implementation.

### Low Priority
- None in scoped diff.

### Edge Cases Found by Scout
- `public/sw.js` message flow is async-safe now via `event.waitUntil(handleGenerateTotp(event))`.
- TOTP math/base32 path is materially improved; SHA-1 vector sanity check matches RFC behavior.
- If `event.ports[0]` is absent, handler returns silently (`public/sw.js:203-207`), making caller failures harder to diagnose.

### Positive Observations
- Removed service-worker dynamic import + Node `Buffer` dependency from OTP path.
- Added proper async handling (`waitUntil`) for `GENERATE_TOTP` message event.
- Base32 normalization improved (padding/whitespace handling) and OTP generation no longer hardcoded.
- Docs now correctly call out extension E2EE sync and background sync as incomplete roadmap items.

### Recommended Actions
1. Add explicit algorithm guard for `md5` in `public/sw.js` and align with backend accepted algorithms.
2. Tighten `test_encrypted_account_http_round_trip_keeps_ciphertext_opaque()` to verify opaque pass-through guarantees rather than plaintext substring absence.
3. Align doc wording with observed runtime integration state for offline OTP.
4. Resolve PDR sync-diagram inconsistency (current-state vs future-state labeling).

### Metrics
- Type Coverage: N/A (not measured in this scoped review)
- Test Coverage: N/A (targeted test passed; full suite not rerun in this review)
- Linting Issues: Not rerun in this review (`npm run build` reported as passed with pre-existing warnings)

### Unresolved Questions
1. Is `md5` intentionally supported long-term for OTP, or should it be deprecated/blocked at API boundary?
2. Is there a planned client integration path for `public/sw.js` OTP message protocol in Phase 00/01, or should docs keep this as non-user-facing groundwork?
