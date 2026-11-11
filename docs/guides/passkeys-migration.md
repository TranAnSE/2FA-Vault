# WebAuthn → Passkeys Migration Runbook (v1.6.0)

> Plan 261009-passkeys-migration, phases 4–6. **Read this before upgrading to
> v1.6.0** if your instance has registered WebAuthn devices (the
> `webauthn_credentials` table is non-empty).

## What happens at v1.6.0

2FA-Vault replaces the abandoned `laragear/webauthn` package with first-party
`laravel/passkeys`. Registered authenticators keep working, but their stored
records must be converted once:

- source table `webauthn_credentials` (laragear format, encrypted PEM keys)
- target table `passkeys` (laravel/passkeys format, COSE/JSON records)

The old table is **retained** as a frozen pre-migration snapshot. Nothing in
v1.6.0 writes to it again.

## Migration paths

### Docker installs (recommended path)

The container entrypoint runs the migration automatically and idempotently
before the service starts whenever `webauthn_credentials` is non-empty and
`passkeys` is empty. The service refuses to start if the migration fails.
**After cutover, run the migration once more** (see "Post-cutover re-run").

### Bare-metal / composer installs (manual order)

```bash
# 0. Backup (mandatory) -- 2FA-Vault ships no dump command; use your platform's, e.g.:
# mysqldump --single-transaction <db> > backup-$(date +%F).sql

# 1. Deploy v1.6.0 code, run composer install + migrations
#    (the passkeys table migration ships with v1.6.0)

# 2. Dry-run: report only, zero writes
php artisan 2fauth:migrate-webauthn-credentials --dry-run

# 3. Convert
php artisan 2fauth:migrate-webauthn-credentials --force

# 4. Structural verification of every converted row
php artisan 2fauth:migrate-webauthn-credentials --force --verify

# 5. Post-cutover re-run (REQUIRED, see below)
php artisan 2fauth:migrate-webauthn-credentials --force
```

## Reading the report

| Metric | Meaning |
| --- | --- |
| Total legacy rows | rows found in `webauthn_credentials` |
| Converted | rows successfully written to `passkeys` |
| Skipped (already converted) | idempotency: `credential_id` already present |
| Failed | conversion failed for that row; row id + reason printed |
| Credential-id length (min/max) | target column is VARCHAR(255); historical ids can reach 510 |
| Null counters / null counters / null aaguid / null alias | null-column counts migrated with defaults |

**Abort rule:** if `Failed > 0` (non-zero exit), **do not cut over traffic**.
Every user whose rows failed keeps `useWebauthnOnly` semantics that can lock
them out of password login; either resolve the rows (restore APP_KEY, fix
corrupt rows) or explicitly set `useWebauthnOnly=false` for each affected
user before proceeding.

## Post-cutover re-run (mandatory)

Old-code containers may still register devices into `webauthn_credentials`
during the deployment window. The migration is idempotent (converted ids are
skipped and reported), so **re-run it after the last old-code container is
gone** to sweep those late registrations.

## Verification semantics (honest scope)

`--verify` performs a structural round-trip (JSON → CredentialRecord →
COSE parse → userHandle/aaguid checks) for every converted row. It cannot
signature-verify production rows: the private keys live on user devices.
The first successful device login per user is the functional proof; failed
logins after migration point at conversion (check the per-row report).

## Rollback

Rolling back to v1.5.x is safe **only before any post-cutover mutation**
(no new registrations, revocations or renames under v1.6.0). v1.6.0 never
writes to `webauthn_credentials`, but deletes/renames performed under the new
code only touch `passkeys` — a rollback re-enables credentials the user
already revoked.

Procedure: restore code, restore the `webauthn_credentials` table from the
backup snapshot, and optionally remove ETL-created rows:
`DELETE FROM passkeys WHERE credential_id IN (SELECT id FROM webauthn_credentials)`.

If rollback must stay available during the soak period, mirror `passkeys`
deletes/renames back into `webauthn_credentials` (documented decision for the
soak window owner).

## Staging rehearsal (required before production)

Rehearse the full order on a **production-copy database on the production DB
driver (MySQL/MariaDB)** — the SQLite test suite cannot reproduce
driver-specific decryption/JSON behaviors. Keep the captured report as
release evidence.

## Known behavior changes (v1.6.0)

- **User verification is always required.** The former
  `user_verification` fast/secure switch was dead code and is not
  reproduced. Security keys **without a configured PIN** now prompt for (or
  require) a PIN; document this in release notes.
- Session ceremonies expire server-side after `passkeys.timeout`
  milliseconds (default 60000, no env override ships with v1.6.0); a stale
  dialog must be restarted.
