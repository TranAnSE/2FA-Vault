# 2FA-Vault — Roadmap

> Honest, focused roadmap for reaching a self-hostable, trustable 1.0. Scoped for an OSS self-host tool — **not** a SaaS product. See [Non-goals](#non-goals) for what we deliberately skip.

Last updated: 2026-05-07

---

## Current state

- ✅ E2EE (client-side Argon2id + AES-256-GCM) — implemented
- ✅ Multi-user + teams — implemented
- ✅ Encrypted backup format — implemented
- ⚠️ PWA — `manifest.json` + `sw.js` present, cache strategies work, **but offline OTP generation is a stub** (`public/sw.js:250-271` returns hardcoded `"000000"`). Service worker also has a broken `import()` call at line 181 that will fail at runtime. No background sync despite the claim in older docs.
- ⚠️ Browser extension — forked from upstream. E2EE unlock and local TOTP/HOTP generation now work against encrypted vault fixtures, but the extension still needs broader runtime coverage, autofill hardening, and release-warning cleanup.
- ❌ Everything below

---

## Phase 0 — Fix existing stubs (do first, small)

> Things that claim to work but don't. Cheap to fix, embarrassing to ship.

- [x] Replace `generateTOTP()` stub in `public/sw.js:250-271` with real TOTP (port OTPHP logic or inline otplib)
- [x] Fix the dynamic `import()` of `crypto.js` in `public/sw.js:181` — use `importScripts()` or inline the needed functions
- [x] Add E2EE endpoint round-trip tests (setup key → encrypt secret → fetch → decrypt client-side). Currently only `FixServiceFieldEncryptionTest` covers the console command, not the HTTP flow.
- [x] Update `CLAUDE.md` lines 52-53 to stop claiming "background sync" and "seamless OTP access" (neither is implemented yet)

## Phase 1 — Extension ↔ E2EE sync

> Without this, the extension cannot read any account from a vault with E2EE enabled. This is blocking before anything else.

Target repo: `2FA-Vault-WebExtension` (with supporting changes in `2FA-Vault` API layer)

- [x] Replace extension's PBKDF2 derivation with Argon2id (via `argon2-browser` or equivalent), bit-compatible with main app's `resources/js/services/crypto.js`
- [x] Verify ciphertext/iv/authTag JSON shape against deterministic encrypted E2E fixtures
- [x] Master password unlock flow in extension popup
- [x] Store derived key in `chrome.storage.session` (auto-clears on browser close)
- [x] Auto-lock after N minutes of idle
- [x] New API endpoint `/api/v1/twofaccounts/encrypted` returning raw ciphertext (no server-side decryption)
- [x] Client-side OTP generation in the extension for encrypted TOTP/HOTP fixtures, including HOTP counter-sync error handling
- [ ] Session handoff from main app tab → extension (postMessage) so users don't re-enter master password when both are open
- [x] Extension E2E test: unlock → list accounts → generate OTP, all with server holding only ciphertext

## Phase 2 — Extension as a daily driver

> Upgrade from "copy/paste helper" to "autofill like a password manager".

- [ ] Content script: detect OTP input fields on login pages
- [ ] Domain matching: suggest account whose issuer/label matches current domain
- [ ] One-click autofill into detected field
- [ ] Quick-add flow: detect QR codes on the current page → add account without leaving the site
- [ ] WebAuthn platform authenticator unlock (biometric / OS PIN) as alternative to master password
- [ ] Keyboard shortcut to open/unlock popup

## Phase 3 — Don't lose user data

> E2EE is useless if losing the master password means losing everything. Also the basics of self-host security.

- [ ] **Shamir secret sharing recovery**: split recovery key into N shares, require K to restore
- [ ] Printable recovery codes (PDF) as the baseline fallback
- [ ] CLI: `php artisan vault:backup` — exports encrypted archive to disk with clear restore instructions
- [ ] CLI: `php artisan vault:restore <file>`
- [ ] Rate limiting on login + account lockout after N failed attempts
- [ ] Security headers: strict CSP, HSTS, X-Frame-Options, Referrer-Policy (one middleware, ship once)
- [ ] Email verification on signup
- [ ] Password reset flow (reviewed against OWASP ASVS)
- [ ] Dependabot enabled + security advisories monitored

## Phase 4 — Account hygiene

> Features that make users *actually use* 2FA-Vault beyond "place to store OTPs".

- [ ] Secret health dashboard: flag weak issuers, duplicates, stale entries (not used in 6 months)
- [ ] Have I Been Pwned integration: alert when any account's email has been in a breach
- [ ] Import: Authy, Microsoft Authenticator, Bitwarden, 1Password, Aegis (already supported upstream)
- [ ] Bulk operations: multi-select delete, move to group, export
- [ ] Search + filter when list has 100+ accounts

## Phase 5 — Teams that work

> The differentiator vs. 1Password/Bitwarden for the TOTP-only use case.

- [ ] Just-in-time team access: grant view for N minutes, auto-revoke
- [ ] Secret access request workflow (member requests → admin approves)
- [ ] Passkey storage alongside TOTP (credentials vault, not just OTP app)
- [ ] Audit log UI: who accessed / modified / shared what, when. Exportable as CSV
- [ ] Role-based access: admin / editor / viewer within a team

## Phase 6 — Polish & contributor-ready

> The boring stuff that decides whether someone trusts your repo enough to self-host it.

- [ ] Dark / light / auto theme
- [ ] Mobile responsive pass on real devices (iOS Safari, Android Chrome)
- [ ] Keyboard navigation + basic a11y (labeled inputs, focus rings, ARIA where needed)
- [ ] Soft delete with 7-day trash + undo for destructive actions
- [ ] i18n: EN + VI shipped, framework in place for community PRs
- [ ] GDPR basics: self-service account export (JSON) + delete account
- [ ] `CONTRIBUTING.md`, `SECURITY.md`, `CODE_OF_CONDUCT.md`
- [ ] Issue + PR templates
- [ ] Pre-commit hooks: pint, phpstan, eslint
- [ ] Conventional commits + auto-generated `CHANGELOG.md`
- [ ] Docker Compose that runs with a single command + a README section that verifies install in under 5 minutes

---

## Non-goals (deliberately skipped)

For clarity, these are **not** on the path to 1.0. They belong to SaaS, not self-host OSS:

- External pen test, SOC 2, SBOM, sigstore-signed releases
- Sentry / Prometheus / structured JSON logging (self-hosters run their own stack)
- Load testing, chaos testing, visual regression suites
- Feature flags, blue/green, canary deploys
- Landing page, demo instance, docs site, blog
- Helm chart, Codespaces devcontainer
- Full WCAG 2.1 AA audit (basic a11y is enough)
- First-run setup wizard, admin impersonation (CLI + README is enough)
- SSO / SAML / SCIM (post-1.0 enterprise if ever)
- CLI as a first-class product, desktop app, mobile apps, Apple Watch companion
- Billing / pricing tiers / usage limits

If any of these matter later, they get promoted out of this list. Until then they are noise.

---

## How to read this roadmap

- Phases are roughly sequential, but within a phase items can parallelize.
- Tick a box only when the change is **merged, tested, and works end-to-end across all affected repos** (see [Cross-repo coordination](../../CLAUDE.md#cross-repo-coordination) in CLAUDE.md).
- When a phase completes, update the "Current state" section at the top.

## Estimate

~3-4 months part-time to close Phases 1-6 honestly. If it starts slipping to 6+ months, something on the list is over-scoped — cut, don't stretch.
