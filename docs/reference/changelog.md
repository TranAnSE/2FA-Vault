# Changelog

All notable changes to 2FA-Vault will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.8.0] - 2026-11-20

### Added

**🗑️ Bulk Revoke for Security Devices**
- Multi-select revocation in Settings → Security devices: per-row checkboxes, select-all, and a single confirmed "Revoke selected" action (plan 261119-1650 phase 5)
- New web-family batch endpoint `POST /webauthn/credentials/bulk-revoke` behind session + CSRF (deliberately NOT api.v1/PAT-reachable: a bulk delete there would grant write-PATs a revoke-all-devices capability that does not exist today)
- Per-id result semantics: foreign/unknown ids surface as `unknown` instead of silent no-ops; partial failures show which devices were skipped; all-or-nothing transaction
- Revoking the LAST device resets the _WebAuthn only_ preference on both the bulk and per-device paths (the per-device reset previously never persisted — see Fixed); translated into all 22 locales; user docs updated

### Security

- `isPublicRemoteUrl` (SSRF guard): IP-like hosts that are not valid public decimal IPv4 (octal/hex/padded shorthand such as `0177.0.0.01`) are now rejected deterministically instead of falling through to a resolver-dependent DNS lookup — browsers resolve such hosts as loopback, an SSRF confusion class (this also un-reds master CI, which had been failing on this since v1.7.0 on runners whose resolver answered those names)

### Fixed

- **WebAuthn-only lockout guard never persisted (#110)**: the per-device revoke's `useWebauthnOnly = false` reset wrote the merged preferences collection (accessor-only attribute), so `save()` persisted nothing — a webauthn-only user who revoked their last device stayed locked out. Both the per-device and the new bulk path now persist via the `preferences->` JSON-path assignment, with a revoke-all test pinning it
- **Register response carries the created user `id`**: the 201 payload now includes `id` and the SPA store hydrates it (the store id stayed undefined on registration-fresh sessions); feature test extended
- **e2e E2EE fixture pair regenerated**: the pinned envelopes were never valid (no password ever matched — 272-combination Argon2 matrix); a committed generator (`tests/e2e/helpers/regen-e2ee-vault.mjs`, fixed-IV byte-identical determinism) now produces them, and the PWA offline + extension popup suites run green on the regenerated fixtures
- **PRF stale-writer guards proven under real row locks**: a MySQL-only concurrency suite drives the enroll/rewrap ceremonies with a second connection rotating the salt at the users-row lock; the full backend suite now also runs on MySQL in CI (a required, merge-blocking job since the v1.8.0 release flip)
- eslint debt cleared (13 errors → 0); e2e harness throttle bugs fixed (`LOGIN_THROTTLE` var name, tunable master-password-verify limiter); changelog backlog honestly relabeled (recovery codes / health scoring / breach monitoring moved under 1.6.0, CLI block labeled 2FA-Vault-CLI v0.3.0)

## [1.7.0] - 2026-11-17

### Added

**🔑 WebAuthn PRF Vault Unlock**
- Passkeys with PRF support (hmac-secret) can unlock the E2EE vault in addition to logging you in — a registered passkey is enrolled per-credential in Settings → Security devices (enrolled rows show a badge plus a Refresh action that re-runs the ceremony)
- Bitwarden-style per-credential RSA-2048 keyset: the PRF output (HKDF-derived wrapping key) wraps a per-credential RSA private key; the public key encapsulates the vault key. The server stores only opaque wrapped blobs (zero knowledge); logs never carry key material or salts
- Enrollment requires a server-verified assertion (possession proof) before anything is stored — a hijacked session cannot plant key material; the `prf` extension is probed at passkey creation so eligibility is known up front
- Master-password rotation re-wraps enrollments without any ceremony (public-key-only); a stale banner with one-click retry covers racing-tab rotation, and enroll/rewrap both carry a salt-hash stale-writer guard (409, re-checked in-transaction under a row lock)
- Unlock screen shows an "Unlock with passkey" button whenever at least one enrollment exists; distinct user feedback for wrong-passkey, stale-blob, and superseded-session cases, translated into all 22 locales
- Lifecycle: E2EE disable/setup and account recovery wipe enrollments; passkey revocation and user deletion cascade via FKs
- 5 API endpoints (`GET/POST encryption/prf/*`, `DELETE encryption/prf/{credentialId}`) behind the WebAuthn gate, E2EE-enabled guard, and per-endpoint rate limits; OpenAPI updated in the 2FA-Vault-API repo
- Real-ceremony e2e lifecycle spec (register → enroll → lock → passkey unlock → rotate → unlock → revoke) wired into `ci:e2e`

### Changed

- Removed the legacy biometric unlock service and its IndexedDB settings (purged automatically); the crypto store now retains the raw vault key for the session to power PRF enroll/re-wrap (documented exfiltration-vs-use risk delta; rotation after suspected compromise is mandatory guidance)

### Fixed

- Bare-`crypto` auto-import hazard (unplugin-auto-import shadowing the WebCrypto global) fixed in the key-sharing, offline-TOTP, and offline sync-queue services, with an ESLint guard preventing regressions

## [1.6.1] - 2026-11-12

### Removed

- `laragear/webauthn` dropped from composer dependencies and the codebase; legacy `webauthn_credentials` table removed via a guarded drop migration (aborts blind 1.5.x → 1.6.1 jumps; `down()` recreates the legacy schema verbatim)

### Security

- Post-soak follow-up to v1.6.0: upgrade THROUGH v1.6.0 first — 1.5.x installs must run the v1.6.0 ETL before updating to v1.6.1

## [1.6.0] - 2026-11-12

### Changed

- **Passkeys engine swap**: abandoned `laragear/webauthn` replaced with first-party `laravel/passkeys` 0.2.1 (plan 261009-passkeys-migration) — the `/webauthn/*` contract is preserved (controllers kept their names/routes; the SPA serializer emits strict base64url)
- One-time ETL shipped in v1.6.0 (entrypoint auto-run) migrating existing WebAuthn credentials to the new engine
- User verification is now REQUIRED on every passkey ceremony; anti-enumeration floor applied symmetrically

### Added

- First-party WebAuthn account recovery flow; SSO-only gate on every ceremony surface

**🔐 Recovery Codes Storage** (landed 2026-06-20, commit 35d21c95 + follow-ups — previously mislabeled as unreleased)
- Encrypted `recovery_codes` text column on `twofaccounts` storing an external-service backup-code list (JSON array), encrypted at rest via the same `notes` pattern (server Crypt or client E2EE ciphertext blob)
- Surfaced in the account create/edit form with a copy-all button; emitted by both the standard and encrypted (`/twofaccounts/encrypted`) account resources so E2EE vaults can read it back
- `recovery_codes` is optional; omitting it on update preserves the existing value, sending `null`/empty clears it

**🩺 Account Health Scoring** (landed 2026-06-20, commit 35d21c95 + follow-ups — previously mislabeled as unreleased)
- `AccountHealthService`: per-account security score from server-visible metadata only (algorithm, digits, period, last-used freshness) — safe under E2EE
- `GET /api/v1/twofaccounts/{id}/health` (per-account, policy-guarded) and `GET /api/v1/twofaccounts/health/summary` (vault-wide grade counts, average, weak-account list)
- Client-side entropy + duplicate-secret scoring (`account-health-client.js`) merged into a combined score when the vault is unlocked; letter-grade badge + `/health` dashboard with a "show weak only" filter

**🛡️ Breach Monitoring (HaveIBeenPwned)** (landed 2026-06-20, commit 35d21c95 + follow-ups — previously mislabeled as unreleased)
- `BreachMonitoringService`: email breach checks (authenticated, opt-in) and service/domain breach checks (public breaches list, no opt-in), 24h hashed cache, retry/backoff on 429/5xx, graceful "unknown" degradation on outage
- `POST /api/v1/breach/check-email` (hard-gated behind the `breachMonitoring` user preference, off by default — the email leaves the system only with explicit opt-in) and `GET /api/v1/breach/check-service`
- `breachMonitoring` user preference, HIBP key via `config/services.php` + `HIBP_API_KEY` env, `BreachBadge` component

**⌨️ CLI Tool extensions** (shipped in 2FA-Vault-CLI v0.3.0, 2026-10-15 — a sibling-repo release, not part of this app's versions)
- Extended the `2fav` Bun/TypeScript CLI with `--search` (list alias), `--watch` (TOTP period-aligned refresh), `--copy` (clipboard), and E2EE detection (clear error when a target account uses E2EE, which v1 does not support)

### Fixed

- `MetricsController` queried a nonexistent `webhook_deliveries.status` column (production MySQL 500 on `/metrics`; sqlite DQS masked it)

## [1.2.0] - 2026-06-14

### Added

**📝 Account Notes (Phase 1)**
- Free-text `notes` field on two-factor accounts (nullable, client-encrypted like the secret)
- Surfaced in account create/edit forms and the account detail view

**⭐ Favorites / Pinned Accounts (Phase 1)**
- `is_pinned` boolean on `twofaccounts`; pinned accounts sort to the top of lists
- Pin/unpin from the account list and detail view

**📋 Personal Audit Log (Phase 1)**
- `personal_activity_logs` table with per-user activity entries
- `PersonalActivityLogController`: `GET /api/v1/user/activity` (paginated) and `DELETE /api/v1/user/activity` (clear)
- Personal activity view under user settings

**💾 Auto-Backup System (Phase 3)**
- `AutoBackupJob` queued job registered on the scheduler; per-user configurable schedule and destinations
- `UserBackupDestination` model + `BackupDestinationController`: CRUD at `/api/v1/user/backup-destinations`, plus `POST /api/v1/user/backup-destinations/{id}/test` for connection verification
- User-facing auto-backup settings page

**✉️ Email Invitations (Phase 1)**
- Admin-managed `UserInvitation` model + `UserInvitationController`: `GET/POST /api/v1/user/invitations` and `DELETE /api/v1/user/invitations/{id}` (admin only)
- Invitation lifecycle management in the admin panel

**📥 Import Formats (Phase 2)**
- New migrators: Raivo JSON, andOTP, FreeOTP+, Authy (Authy marked BETA in the UI)
- Importer registration and format auto-detection

**🩺 Vault Health — Weak Secrets (Phase 4, frontend)**
- Client-side weak-secret detection surfaced in the existing Vault Health UI
- Flags short-digit / low-entropy / shared secrets for review

**🔐 Session Management (Phase 1)**
- `user_sessions` table tracking active sessions (device, IP, last-active)
- `UserSessionController`: `GET /api/v1/user/sessions` and `DELETE /api/v1/user/sessions/{id}` to revoke a session
- Sessions management page under user settings

**📊 Prometheus Metrics (Phase 2)**
- `/metrics` endpoint exposing metrics in Prometheus text exposition format
- Auth via configurable IP allowlist or bearer token

**🗂️ Secure Notes (Phase 1)**
- `secure_notes` table with client-encrypted, pinnable notes
- `SecureNoteController`: CRUD at `/api/v1/secure-notes` plus `POST /api/v1/secure-notes/{id}/pin`
- Secure Notes view under user settings

### Changed
- `TwoFAccount` responses now include `notes` and `is_pinned`
- Admin panel expanded with Email Invitations management
- User settings expanded with Auto-Backup, Sessions, Personal Activity, and Secure Notes sections

### Database Migrations (2026-06-14)
- `add_notes_to_twofaccounts_table`
- `add_is_pinned_to_twofaccounts_table`
- `create_personal_activity_logs_table`
- `create_user_sessions_table`
- `create_user_backup_destinations_table`
- `create_secure_notes_table`

### Tests
- ~66 new tests across the v1.2.0 features
- 1590 total PHPUnit tests passing, 0 failures (up from 1524)
- Playwright e2e tests deferred (require a running app/browser) — noted as follow-up

### Known Issues
- Browser extension `npm run build` fails on a pre-existing stale `@2fauth/ui` dist in `2FA-Vault-Components` (missing `StackLayout` export). Not caused by this release; tracked in the components repo.

## [1.1.2] - 2026-06-10

### Added

**Enterprise Feature Test Coverage (7 phases)**

- **Test Factories**: Created 5 missing Eloquent factories (EmergencyContact, EmergencyAccessRequest, Webhook, Vault, Tag); added `HasFactory` trait to 4 models
- **Emergency Access Tests**: 27 tests across service, controller, and console command — contact CRUD, access request lifecycle, dead man's switch processing, authorization boundaries
- **Webhook Tests**: 20 tests across service and controller — HMAC signature generation, event type validation, delivery history, Queue::fake for job dispatch
- **Vault Tests**: 16 tests across service and controller — 10-vault limit enforcement, default vault protection, ownership boundaries
- **Tag Tests**: 16 tests across model and controller — CRUD with colors, duplicate name prevention, many-to-many associations
- **Team Activity Tests**: 14 tests across logger service and controller — all 11 TeamAction enum cases, authorization, pagination
- **CryptoTest Fixes**: Replaced 2 `assertTrue(true)` placeholders with 5 real zero-knowledge verification tests

### Fixed

- `EmergencyAccessService::processExpiredRequests()` — `diffInDays()` returning negative values for expired requests
- `EmergencyAccessService::checkDeadMansSwitch()` — same `diffInDays()` sign bug
- `TagController` — missing `use App\Http\Controllers\Controller` import

### Tests

- 118 new tests, ~350 new assertions
- 1524 total tests passing, 0 failures

## [1.1.1] - 2026-06-10

### Added

**Security Hardening (7 phases)**

- **CORS Hardening**: `CORS_MAX_AGE` default changed from `0` to `86400` (24h preflight cache); configurable `CORS_ALLOWED_METHODS`, `CORS_ALLOWED_ORIGINS`, `CORS_ALLOWED_HEADERS` via env
- **Session Encryption**: `SESSION_ENCRYPT` default changed from `false` to `true`; all session payloads encrypted server-side via Laravel's built-in encrypter
- **Database Indexes**: Migration `2026_06_09_120000` adding composite indexes on `twofaccounts` (user_id, order_column, group_id, last_used_at, encrypted) and `groups` (user_id) — idempotent, safe to re-run
- **Rate Limiting**: Throttle enforced on login, registration, and password-reset endpoints; `AuthRateLimitTest` validates 429 responses
- **Backup Encryption**: `BackupService` enforces encrypted export by default; `BackupController` validates encryption state before export
- **CSP Extension**: `ContentSecurityPolicyMiddleware` updated with frame-src and worker-src allowances for browser extension integration
- **Encryption Disable Safety**: `EncryptionServiceDisableTest` and `EncryptionControllerDisableTest` ensure graceful degradation when E2EE is disabled — no data loss, clear user feedback

### Security

- Session payloads now encrypted at rest (mitigates session hijacking via storage access)
- CORS preflight caching reduces attack surface from repeated OPTIONS requests
- Auth endpoints rate-limited to prevent brute-force attempts
- Database indexes improve query performance and reduce timing-based information leakage

### Breaking Changes

- `SESSION_ENCRYPT` default changed from `false` to `true`. Existing sessions are invalidated after upgrade; users must re-authenticate.
- `CORS_MAX_AGE` default changed from `0` to `86400`. Browsers will cache preflight responses for 24 hours. If you previously relied on per-request preflight, set `CORS_MAX_AGE=0` in your `.env`.

### Tests

- 34 new security-focused tests added
- 1428 total tests passing

## [1.1.0] - 2026-06-07

### Added

**🔌 Browser Auto-Fill OTP (Phase 6)**
- Extension content script (`auto-fill.content.js`) detects 2FA input fields on web pages
- Domain-to-account matching with confidence scoring; fills OTP automatically
- OTP cleared from DOM after 30 seconds; default OFF (user must enable in extension settings)
- Auto-fill toggle in extension Options settings page

**📊 Vault Health Dashboard (Phase 7)**
- Admin dashboard at `/admin/health` showing overall vault health score (0-100)
- Duplicate secret detection, unused account detection (90-day threshold), secret strength analysis
- Health gauge component with color-coded score; export report as JSON
- `last_used_at` field added to `twofaccounts` table, updated on every OTP generation

**🔑 Encrypted Key Sharing (Phase 8)**
- RSA-OAEP key pair generation per user, public key stored on server
- Per-member wrapped key storage in `shared_accounts.wrapped_key`
- Team owner can share accounts with E2EE key wrapping — server never sees plaintext secret
- New endpoints: `POST /teams/{id}/share-encrypted`, `GET /teams/{id}/members/{userId}/public-key`, `POST /user/public-key`

**👆 Biometric Unlock (Phase 9)**
- WebAuthn platform authenticator enrollment for fingerprint/Face ID unlock
- Encrypted master key storage in IndexedDB; biometric auth triggers decryption
- Available in both main app (UnlockVault.vue, Encryption.vue) and extension popup
- Biometric enrollment/management in Settings → Encryption

**🔄 Background Sync PWA (Phase 10)**
- Background Sync API handler in service worker (`sw.js`)
- Offline operation queue in IndexedDB; auto-syncs on network restoration
- `syncService.js` coordinates Background Sync registration with SW fallback
- `OfflineIndicator.vue` now shows pending/failed operation counts

**🏷️ Account Tags & Labels (Phase 11)**
- Full tags CRUD: `GET/POST/PUT/DELETE /api/v1/tags`
- Many-to-many `account_tag` pivot; up to 10 tags per account
- `TagBadge.vue` and `TagInput.vue` components; Tags management page at `/tags`
- `TwoFAccountController::index` supports `tags`, `tag_mode` filter params

**🔍 Advanced Search & Filter (Phase 12)**
- Client-side full-text search engine (`searchService.js`) for encrypted vaults
- Server-side filtering by `q`, `types`, `algorithms`, `digits`, `group_id`, `tags`, `encrypted`, `last_used_from/to`, `sort`
- `FilterPanel.vue` collapsible filter UI with saved presets (localStorage)

**📋 Team Activity Log (Phase 13)**
- `team_activity_logs` table with indexed queries; 90-day auto-prune
- `TeamActivityLogger` service injects into `TeamController` for fire-and-forget logging
- Activity log view at `/teams/:id/activity` with pagination and JSON export
- Logged actions: team created/updated, member joined/left/removed, role changed, account shared/unshared

**🆘 Emergency Access / Dead Man's Switch (Phase 14)**
- Designate up to 5 trusted contacts with configurable wait periods (7–90 days)
- Trusted contact requests access; owner has wait_days to approve/deny before auto-grant
- Dead man's switch: if owner is inactive for `wait_days`, access is auto-granted
- Scheduled command `emergency:process` runs daily at 03:00
- Settings page at `/settings/emergency`

**🗃️ Multiple Vaults / Sub-Vaults (Phase 15)**
- `vaults` table with per-vault encryption keys; `vault_id` FK on `twofaccounts`, `groups`, `tags`
- Up to 10 vaults per user; default vault auto-created; non-default vaults deletable
- `VaultService` for vault lifecycle management
- Settings page at `/settings/vaults`

**🎯 Extension Form Detection & Badge (Phase 16)**
- `detector.content.js` runs on all pages, detects login/2FA forms
- Background updates extension badge count with matching account count
- `domainMappingService.js` persists user-confirmed domain→account mappings
- Page context stored in session storage for popup to read on open

**⚡ Admin Rate Limit Dashboard (Phase 17)**
- `rate_limit_logs` table with indexed queries
- `RateLimitMonitorService` with fire-and-forget logging after response
- Admin dashboard at `/admin/rate-limits` showing total requests, limited requests, top consumers, top endpoints
- Supports 24h/7d/30d time windows

**🔔 Webhook / Event System (Phase 18)**
- `webhooks` and `webhook_deliveries` tables
- `WebhookEvent` enum with 13 event types (account, team, auth, vault events)
- `WebhookDeliveryJob` queued job with 3 retry attempts and HMAC-SHA256 signature
- Webhook CRUD + test endpoint + delivery history at `/settings/webhooks`
- `WebhookService::dispatch()` for fire-and-forget event delivery

### Changed
- `TwoFAccount` responses now include `last_used_at` and `tags[]` fields
- Admin tabs expanded with Vault Health and Rate Limits sections
- `shared_accounts` table gained `member_id` and `wrapped_key` columns
- `users` table gained `public_key` column
- Service worker `sw.js` now handles `sync` events and `PROCESS_SYNC_QUEUE` messages

### Database Migrations (2026-06-07)
- `add_last_used_at_to_twofaccounts_table`
- `add_public_key_to_users_table`
- `add_member_key_to_shared_accounts_table`
- `create_tags_table` (+ `account_tag` pivot)
- `create_team_activity_logs_table`
- `create_emergency_access_tables`
- `create_vaults_table` (+ `vault_id` on twofaccounts/groups/tags)
- `create_rate_limit_tables`
- `create_webhooks_table`

## [1.0.0] - 2026-04-04

### Added

**🔐 Zero-Knowledge Encryption**
- End-to-end encryption (E2EE) with client-side encryption
- Argon2id key derivation (64 MB memory cost, 3 iterations, parallelism 4)
- AES-256-GCM authenticated encryption
- Zero-knowledge architecture (server cannot decrypt user data)
- Encryption enabled by default for all new accounts
- Encrypted backup format (.vault) with integrity verification

**👥 Multi-User & Team Management**
- Multi-user support with team workspaces
- Team creation and management
- Team invite system with expiring codes
- User can join multiple teams (configurable limit)
- Personal team created automatically on signup

**🔑 Role-Based Access Control (RBAC)**
- Four roles: Owner, Admin, Member, Viewer
- Granular permissions per role
- Owner: Full control (delete team, manage all members)
- Admin: Manage accounts and members (cannot delete team)
- Member: Create/edit/delete own accounts only
- Viewer: Read-only access (view accounts, generate codes)

**💾 Enhanced Backup System**
- Encrypted backup export (.vault format)
- Encrypted backup import with password verification
- Password-protected backups
- Backup integrity checks (HMAC-SHA256)
- Migration tool from 2FA-Vault JSON format
- Automatic backup encryption on export

**🌐 Browser Extension**
- Chrome Manifest V3 extension
- Firefox WebExtension support
- One-click TOTP code copy
- Auto-fill for web forms
- Offline mode with IndexedDB sync
- Biometric unlock (WebAuthn) in extension

**📱 Progressive Web App (PWA)**
- Installable on desktop and mobile
- Offline mode with service worker
- Background sync for account updates
- Add to home screen support
- App-like experience (fullscreen, splash screen)
- Cache strategies (network-first, cache-first, stale-while-revalidate)

**🔔 Push Notifications**
- Web Push API integration
- VAPID protocol for authentication
- Push subscription management
- Notification permissions handling
- Customizable notification settings

**📴 Offline TOTP Generation**
- IndexedDB for encrypted local storage
- Offline code generation without server
- Service worker caching strategies
- Background sync when online
- Conflict resolution for offline changes

**🛡️ Security Enhancements**
- Biometric unlock (WebAuthn)
- Hardware security key support (FIDO2)
- Audit logging for sensitive operations
- Rate limiting on authentication endpoints
- CSRF protection
- Content Security Policy (CSP) headers
- HTTP Strict Transport Security (HSTS)
- X-Frame-Options: DENY
- X-Content-Type-Options: nosniff

**👨‍💼 Admin Panel**
- User management interface
- Team overview and statistics
- System settings configuration
- User account activation/deactivation
- Audit log viewer
- Storage usage monitoring

**🎨 UI/UX Improvements**
- Dark mode support
- Responsive design (mobile-first)
- Improved accessibility (WCAG 2.1 AA)
- Loading states and skeleton screens
- Toast notifications
- Drag-and-drop account reordering

**🚀 DevOps & Infrastructure**
- CI/CD pipeline (GitHub Actions)
- Automated testing (PHPUnit, Jest)
- Docker production configuration
- Redis for session and cache storage
- MySQL 8.0 database
- Health check endpoints
- Logging and monitoring

### Changed

**🔐 Encryption Now Default**
- Encryption is **ON by default** (was optional in 2FA-Vault)
- Master password required for all accounts
- Automatic encryption of legacy data on first login
- Migration script for unencrypted 2FA-Vault data

**📊 Database Schema**
- Multi-user schema (teams, roles, memberships)
- Foreign key constraints for referential integrity
- Optimized indexes for team queries
- Audit log table for compliance

**🔑 Authentication Flow**
- Separate master password for encryption (not login password)
- Master password never sent to server
- Client-side key derivation
- Session management with Redis

**📦 Backup Format**
- Changed from `.json` (plaintext) to `.vault` (encrypted)
- Breaking change: Old 2FA-Vault backups require migration
- Migration tool provided (see MIGRATION.md)

**🎯 Project Metadata**
- Forked from 2FA-Vault v6.1.3
- Renamed to 2FA-Vault
- New branding and logo
- Updated documentation

### Fixed

- **Security:** XSS vulnerability in account name rendering
- **Security:** CSRF token validation on backup export
- **Performance:** N+1 query on team accounts listing
- **Bug:** Service worker not updating on new deployment
- **Bug:** IndexedDB quota exceeded on large accounts (&gt;100 items)
- **Bug:** Push notifications not working on Firefox Android

### Security

- **CRITICAL:** Implemented E2EE to protect against server-side data breaches
- **HIGH:** Argon2id prevents GPU-based password cracking
- **MEDIUM:** Rate limiting prevents brute-force attacks
- **MEDIUM:** CSP headers prevent XSS attacks

### Deprecated

- **Legacy encryption method** (Laravel's built-in encryption)
  - Still supported for migration only
  - Will be removed in v2.0.0
  - Migrate to Argon2id + AES-256-GCM

### Removed

- **Single-user mode** (replaced with personal teams)
- **Plaintext JSON backups** (replaced with encrypted .vault)
- **Password recovery** (impossible with zero-knowledge encryption)
  - Users must backup .vault file
  - No "forgot password" feature by design

### Breaking Changes

⚠️ **Migration from 2FA-Vault required** - see [MIGRATION.md](MIGRATION.md)

1. **Backup format:** `.json` → `.vault` (encrypted)
2. **Database schema:** Single-user → Multi-user
3. **API endpoints:** New E2EE endpoints, some modified
4. **Environment variables:** New required variables (see .env.example)
5. **Password system:** Login password ≠ Master password

### Migration Path

```bash
# Export from 2FA-Vault
Settings → Backup → Export accounts → 2FA-Vault-backup.json

# Install 2FA-Vault
docker-compose up -d

# Import to 2FA-Vault
Settings → Import → Choose "2FA-Vault JSON" → Upload file
```

Detailed migration guide: [MIGRATION.md](MIGRATION.md)

## [0.x.x] - 2FA-Vault Versions

This project is a fork of [2FA-Vault](https://github.com/TranAnSE/2FA-Vault) v6.1.3.

For 2FA-Vault changelog history, see:
https://github.com/TranAnSE/2FA-Vault/blob/master/CHANGELOG.md

---

## Versioning Scheme

- **MAJOR.MINOR.PATCH** (Semantic Versioning)
- **MAJOR:** Breaking changes, major features
- **MINOR:** New features, backward-compatible
- **PATCH:** Bug fixes, security patches

## Support

- **GitHub Issues:** https://github.com/yourusername/2FA-Vault/issues
- **Security:** security@2fa-vault.example.com
- **Documentation:** https://docs.2fa-vault.example.com

## Acknowledgments

- **2FA-Vault:** Original project by [Bubka](https://github.com/Bubka)
- **Laravel:** Backend framework
- **Vue.js:** Frontend framework
- **Argon2:** Password hashing by Daniel J. Bernstein et al.
- **Web Crypto API:** Browser encryption primitives

---

[1.0.0]: https://github.com/yourusername/2FA-Vault/releases/tag/v1.0.0
[1.1.0]: https://github.com/yourusername/2FA-Vault/releases/tag/v1.1.0
[1.1.1]: https://github.com/yourusername/2FA-Vault/releases/tag/v1.1.1
[1.1.2]: https://github.com/yourusername/2FA-Vault/releases/tag/v1.1.2
[1.2.0]: https://github.com/yourusername/2FA-Vault/releases/tag/v1.2.0
[Unreleased]: https://github.com/yourusername/2FA-Vault/compare/v1.2.0...HEAD
