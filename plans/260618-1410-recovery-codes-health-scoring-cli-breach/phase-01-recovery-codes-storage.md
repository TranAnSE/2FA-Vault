# Phase 1 — Recovery Codes Storage

## Context Links

- Plan overview: [plan.md](plan.md)
- Brainstorm: [brainstorm-report.md](brainstorm-report.md) (Feature 1)
- Research: [research/researcher-01-recovery-codes-hibp.md](research/researcher-01-recovery-codes-hibp.md)
- Reference impl (mirror this): `notes` field added in v1.2.0
  - `database/migrations/2026_06_13_000001_add_notes_is_pinned_to_twofaccounts_table.php`
  - `app/Models/TwoFAccount.php` (`getNotesAttribute`/`setNotesAttribute`, lines 401-420; `$fillable` 126-129)

## Overview

- **Priority:** P2
- **Status:** pending
- **Effort:** 3d
- **Description:** Add an encrypted, nullable `recovery_codes` text column to `TwoFAccount`,
  storing a JSON string array (`["code1","code2",...]`). Reuse the exact `notes` pattern:
  `CanEncryptField` getter/setter, fillable, resource field, request validation, E2EE
  client-side encryption. No new model, no per-code tracking (YAGNI).

## Key Insights

- Research recommends bcrypt-hashed codes in a separate table for *authentication* recovery codes.
  That does NOT apply here: these are the user's external-service backup codes (e.g. GitHub's 10
  codes) that the user must be able to *view later*. View-later requires reversible storage, so the
  `notes`-style encrypted-text pattern is correct. Document this divergence so reviewers don't
  "fix" it to hashing.
- `setSecretAttribute` already distinguishes E2EE payloads by detecting a leading `{` + `ciphertext`.
  Recovery codes follow the simpler `notes` path: `encryptOrReturn`/`decryptOrReturn` (server-side
  Laravel Crypt). When E2EE is on, the client encrypts the JSON before sending, and the value is
  already a JSON ciphertext blob — store as-is, same as `notes` does today.
- `fillWithOtpParameters` only assigns new fillable fields when `Arr::has(...)` (see notes/is_pinned
  at lines 535-542). Mirror this guard for `recovery_codes` so updates without the field don't wipe it.

## Requirements

**Functional**
- Store/retrieve a list of recovery codes per account via the existing account store/update/show API.
- `recovery_codes` is optional; absent on store/update leaves existing value unchanged.
- Frontend account detail view shows codes (read-only), supports edit, and a "copy all" button.

**Non-functional**
- At-rest encryption: server-side `Crypt` (non-E2EE) or client ciphertext (E2EE) — never plaintext in DB.
- No new endpoints; reuse `twofaccounts` resource routes.
- Backward compatible: column nullable, old clients ignore the field.

## Architecture

Data flow (non-E2EE): client sends `recovery_codes` JSON string → `TwoFAccountStoreRequest`
validates → `fillWithOtpParameters` sets attribute → `setRecoveryCodesAttribute` calls
`encryptOrReturn` → stored. On read: `getRecoveryCodesAttribute` calls `decryptOrReturn` →
`TwoFAccountReadResource` exposes value.

E2EE: client encrypts the JSON array to `{ciphertext,iv,authTag}` before the API call (same crypto
service path as `notes`), stores ciphertext blob; client decrypts after fetch.

## Related Code Files

**Create**
- `database/migrations/2026_06_18_000001_add_recovery_codes_to_twofaccounts_table.php`
- `tests/Api/v1/TwoFAccountRecoveryCodesTest.php`

**Modify**
- `app/Models/TwoFAccount.php` — add `'recovery_codes'` to `$fillable`; add
  `getRecoveryCodesAttribute`/`setRecoveryCodesAttribute` (mirror notes); add `Arr::has` guard in
  `fillWithOtpParameters`; add `@property string|null $recovery_codes` to docblock.
- `app/Api/v1/Resources/TwoFAccountStoreResource.php` — add `'recovery_codes' => $this->recovery_codes`.
- `app/Api/v1/Requests/TwoFAccountStoreRequest.php` — add `'recovery_codes' => 'nullable|string'`.
- `app/Api/v1/Requests/TwoFAccountUpdateRequest.php` — add `'recovery_codes' => 'sometimes|nullable|string'`.
- `resources/js/views/` — account detail/edit view: recovery codes section (textarea read-only +
  edit, copy-all button, empty-state placeholder).
- `resources/js/services/twofaccountService.js` (or the account form component) — include
  `recovery_codes`; apply same E2EE encrypt-before-send / decrypt-after-fetch path used for `notes`.

**Cross-repo**
- `2FA-Vault-API/` — add `recovery_codes` (nullable string) to the `TwoFAccount` schema + Store/Update
  request bodies in the OpenAPI YAML; run `npm run validate:openapi`.
- `2FA-Vault-WebExtension/` — check whether extension parses the account payload strictly; a new
  nullable field should be ignored, but verify deserialization does not reject unknown keys.

## Implementation Steps

1. Create migration: `$table->text('recovery_codes')->nullable()->after('notes');` + `down()` dropping it.
2. `TwoFAccount.php`: add to `$fillable`; add getter/setter mirroring `getNotesAttribute`/`setNotesAttribute`.
3. `TwoFAccount.php`: in `fillWithOtpParameters`, add
   `if (Arr::has($parameters,'recovery_codes')) { $this->recovery_codes = Arr::get($parameters,'recovery_codes'); }`.
4. Add field to `TwoFAccountStoreResource::toArray`.
5. Add validation rule to both Store and Update requests.
6. `php artisan migrate`; run `composer test` to confirm no regressions.
7. Frontend: extend account form/detail view + service to carry `recovery_codes`; mirror notes E2EE path.
8. Write `tests/Api/v1/TwoFAccountRecoveryCodesTest.php` (see Success Criteria).
9. Update OpenAPI spec in `2FA-Vault-API`; verify extension payload handling.

## Todo List

- [ ] Migration created and runs
- [ ] Model fillable + getter/setter + fillWithOtpParameters guard
- [ ] Store resource exposes field
- [ ] Store + Update requests validate field
- [ ] Frontend detail/edit UI + copy-all + E2EE path
- [ ] API tests pass
- [ ] OpenAPI spec updated and validated
- [ ] Extension payload handling verified

## Success Criteria

- Store account with `recovery_codes` → GET returns same array; DB value is ciphertext, not plaintext
  (assert stored column != submitted JSON).
- Update without `recovery_codes` preserves existing value; update with `null`/`[]` clears it.
- E2EE account: server stores opaque ciphertext blob and never logs/returns plaintext.
- `composer test` and `npm run lint` green.

## Risk Assessment

- **Risk:** Reviewer converts to bcrypt hashing per generic research. **Mitigation:** Key Insights
  documents the view-later requirement; codes are external backup codes, not auth recovery codes.
- **Risk:** Large code lists bloat the row. **Mitigation:** `text` column; UX caps display, not storage.
- **Risk:** E2EE path mismatch with `notes`. **Mitigation:** Reuse the identical client crypto helper.

## Security Considerations

- Never log recovery codes (model already logs only IDs).
- DB at-rest encryption mandatory (server Crypt or client E2EE ciphertext).
- Authorization: reuse `TwoFAccountPolicy` via existing resource routes (no new endpoint).

## Next Steps

- None blocking. Coordinate the shared `TwoFAccountStoreResource` edit with Phase 2 if done in parallel.
