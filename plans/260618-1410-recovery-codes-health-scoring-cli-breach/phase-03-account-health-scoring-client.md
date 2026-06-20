# Phase 3 — Account Health Scoring (Client-Side Secret Checks)

## Context Links

- Plan overview: [plan.md](plan.md)
- Depends on: [phase-02-account-health-scoring-server.md](phase-02-account-health-scoring-server.md)
- Brainstorm: [brainstorm-report.md](brainstorm-report.md) (Feature 2, client-side table)
- Reference: `resources/js/services/crypto.js` (client crypto), `resources/js/stores/crypto.js`
  (unlock state), existing "Vault Health Dashboard" mention in `CLAUDE.md` (duplicate/weak detection
  may already have partial client utilities — check before building new).

## Overview

- **Priority:** P2
- **Status:** pending
- **Effort:** 2d
- **Description:** Extend health scoring with checks that require decrypted secrets, run entirely
  client-side after vault unlock (E2EE safe). Adds secret-entropy and duplicate-secret scores, then
  merges server + client into a `combined_score`. No backend changes.

## Key Insights

- These checks require plaintext secrets, which exist only client-side. The server must never receive
  them — implement purely in JS, only when the vault is unlocked.
- `CLAUDE.md` already lists a "Vault Health Dashboard: client-side health scoring, duplicate/unused/
  weak-secret detection." MUST scout `resources/js/` for an existing health/duplicate utility before
  creating `account-health-client.js` to avoid DRY violation. Reuse/extend if present.
- Secret entropy = Base32-decode the secret → byte length. RFC 4226 recommends >= 20 bytes (160 bits).
- Duplicate detection compares decrypted secrets across all loaded accounts; O(n) with a hash map.
- When vault is locked, show server-only score (Phase 2); when unlocked, show combined.

## Requirements

**Functional**
- `computeClientScore(accounts)` → `Map<id, {entropy_score, duplicate_score, client_total}>`.
- Merge into `combined_score`: define weighting (e.g. `combined = round(0.6*server_total +
  0.4*client_total)`) — document the chosen split in code constants.
- Badge + dashboard show combined score when unlocked, server-only when locked, with a clear indicator.
- Detail view shows entropy and duplicate indicators in the health breakdown.

**Non-functional**
- Pure function, no network. Tolerate undecodable secrets gracefully (entropy_score = 0, no throw).
- No plaintext secret leaves the browser or hits logs.

## Architecture

```
entropy_score:   decoded bytes <10 = 0, 10-15 = 50, 16-19 = 75, >=20 = 100
duplicate_score: secret appears once = 100, appears >1 across accounts = 0
client_total = round((entropy_score + duplicate_score) / 2)
combined_score = round(0.6*server_total + 0.4*client_total)   // constants, tunable
```

Flow: after unlock, decrypt accounts (existing path) → `computeClientScore(decryptedAccounts)` →
`useAccountHealth` merges with server scores → UI renders combined grade.

## Related Code Files

**Create**
- `resources/js/services/account-health-client.js` — `computeClientScore`, Base32 decode helper
  (reuse existing crypto util if present), duplicate map. Keep < 200 lines.
- `resources/js/services/__tests__/account-health-client.spec.js` (or project test location) —
  known-input vectors.

**Modify**
- `resources/js/composables/useAccountHealth.js` (from Phase 2) — accept decrypted accounts, call
  `computeClientScore`, expose `combined_score` + lock-aware grade.
- `resources/js/components/AccountHealthBadge.vue` — combined vs server-only display + indicator.
- `resources/js/views/AccountHealthDashboardView.vue` — show entropy/duplicate columns when unlocked.
- Account detail view — entropy + duplicate indicators in breakdown.

**No backend changes. No cross-repo changes** (client-only; extension has its own health logic if any).

## Implementation Steps

1. Scout `resources/js/` for existing health/duplicate/entropy utilities; decide reuse vs create.
2. Implement `account-health-client.js` (`computeClientScore`, entropy via Base32 decode, duplicate map).
3. Unit-test with known vectors: 10-byte vs 20-byte secrets; two identical secrets → both score 0.
4. Extend `useAccountHealth` to merge server + client into `combined_score`; handle locked state.
5. Update badge + dashboard + detail to show combined/server-only with indicator.
6. Run `npm run lint` and frontend tests.

## Todo List

- [ ] Scouted existing client health utilities (reuse decision recorded)
- [ ] account-health-client.js implemented
- [ ] Client score unit tests pass
- [ ] useAccountHealth merges combined_score, lock-aware
- [ ] Badge/dashboard/detail updated with indicators
- [ ] Lint + frontend tests green

## Success Criteria

- Two accounts with identical secrets both get `duplicate_score = 0`.
- 20-byte secret → entropy 100; 12-byte → 50; <10 bytes → 0.
- Locked vault shows server-only grade; unlocked shows combined; indicator distinguishes them.
- No secret material in network requests or console logs.

## Risk Assessment

- **Risk:** Duplicating existing client health logic. **Mitigation:** mandatory scout step first.
- **Risk:** Malformed/short secrets throw on decode. **Mitigation:** try/catch → entropy_score 0.
- **Risk:** Combined weighting disputes. **Mitigation:** constants + documented rationale.

## Security Considerations

- All computation client-side; plaintext secrets never serialized to network or storage.
- Only runs when vault unlocked; gracefully degrades to server-only when locked.

## Next Steps

- None. Closes the health-scoring feature set.
