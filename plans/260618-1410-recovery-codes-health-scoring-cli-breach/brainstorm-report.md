# Brainstorm Report — Recovery Codes, Health Scoring, CLI, Breach Monitoring

Date: 2026-06-18  
Plan: `plans/260618-1410-recovery-codes-health-scoring-cli-breach/`

---

## Problem Statement

Bổ sung 4 tính năng mới cho 2FA-Vault:
1. Recovery Codes Storage — lưu backup codes đi kèm mỗi 2FA account
2. Account Health Scoring — đánh giá chất lượng bảo mật từng account
3. CLI Tool — generate OTP từ terminal/script
4. Breach Monitoring — cảnh báo khi service/email bị breach

---

## Feature 1: Recovery Codes Storage

### Recommended: JSON field `recovery_codes` trong `TwoFAccount`

**Rationale:**
- Pattern giống `notes` field (v1.2.0) — `CanEncryptField` trait, getter/setter, E2EE client-encrypt
- 1 migration nhỏ, không có model mới, cùng vòng đời với account
- KISS/YAGNI: không cần per-code tracking, user quản lý thủ công

**Schema:**
```sql
ALTER TABLE twofaccounts ADD COLUMN recovery_codes TEXT NULL;
```
Stored as encrypted JSON array: `["code1","code2",...]` hoặc `{"ciphertext":"...","iv":"...","authTag":"..."}` khi E2EE.

**Rejected alternatives:**
- Separate `RecoveryCode` model — over-engineered, YAGNI (no per-code tracking needed)
- Reuse `SecureNote` — wrong abstraction, bad UX

**Affected files:**
- `database/migrations/` — new migration
- `app/Models/TwoFAccount.php` — add getter/setter/fillable
- `app/Api/v1/Resources/TwoFAccountReadResource.php` — add field to response
- `app/Api/v1/Requests/TwoFAccountStoreRequest.php` + UpdateRequest — add validation
- `resources/js/views/` — account detail view, show/edit recovery codes textarea
- `2FA-Vault-API/` — OpenAPI spec update

---

## Feature 2: Account Health Scoring

### Recommended: Hybrid (server metadata + client-side secrets)

**Constraint:** E2EE zero-knowledge — server không thể đọc plaintext secrets khi E2EE bật.

**Server-side checks (always available):**
| Check | Weight | Logic |
|---|---|---|
| Algorithm strength | 30% | md5=0, sha1=50, sha256/sha512=100 |
| Digits count | 20% | 4=50, 5=75, 6+=100 |
| Last used freshness | 30% | NULL=50, >6mo=60, recent=100 |
| Period validity | 20% | <15s=50, normal=100 |

**Client-side checks (post-decrypt, E2EE only):**
| Check | Weight | Logic |
|---|---|---|
| Secret entropy | 50% | Base32 decoded < 20 bytes = weak |
| Duplicate secret | 50% | Same secret as another account = 0 |

**Final score:** A (90+), B (75-89), C (60-74), D (40-59), F (<40)

**API:** `GET /api/v1/accounts/{id}/health` → breakdown JSON
**Frontend:** Badge trong account list + `/health` view với "show weak only" filter

**Affected files:**
- `app/Http/Controllers/` — new `AccountHealthController`
- `app/Services/` — new `AccountHealthService`
- `routes/api/v1.php` — new route
- `resources/js/` — health badge component, health dashboard view
- `resources/js/services/` — client-side health calculation service
- `2FA-Vault-API/` — OpenAPI spec update

---

## Feature 3: CLI Tool (Go binary)

### Recommended: Go binary, thin HTTP client

**No backend changes needed** — calls existing API endpoints.

**Commands:**
```bash
2fv otp "GitHub"         # Get current OTP
2fv otp "GitHub" --watch # Auto-refresh every period
2fv list                 # List all accounts
2fv otp "GitHub" --copy  # Copy to clipboard
2fv config set host https://vault.example.com
2fv config set token <PAT>
```

**Config:** `~/.2fv/config.toml`  
**Stack:** Go (single binary ~3MB, cross-platform)  
**Repo:** New `2FA-Vault-CLI/` sibling repo, or inside `2FA-Vault-Docs/` as reference

**No E2EE support in v1** — CLI only works with non-E2EE vaults or plaintext PAT auth.

---

## Feature 4: Breach Monitoring (HIBP)

### Recommended: HaveIBeenPwned API integration

**Two check types:**
1. **Email breach check** — has the user's email appeared in known breaches?
2. **Service breach check** — has a service name appeared in HIBP's breach database?

**Privacy-safe approach:**
- Email: use k-anonymity model (send first 5 chars of SHA1 hash, compare locally)
- Service name: query HIBP breach list by name (public API, no k-anonymity needed)

**When to check:**
- On demand: user clicks "Check for breaches" in account detail
- Optionally: scheduled daily job in background
- Push notification if new breach found

**Affected files:**
- `app/Services/BreachMonitoringService.php` — new service
- `app/Http/Controllers/BreachController.php` — new controller
- `routes/api/v1.php` — new routes
- `resources/js/` — breach badge + notification
- Settings: enable/disable breach monitoring per user

---

## Implementation Order

| Phase | Feature | Effort | Notes |
|---|---|---|---|
| 1 | Recovery Codes | S (3-4 days) | Self-contained, no deps |
| 2 | Account Health — server metadata | M (3-4 days) | No deps |
| 3 | Account Health — client-side | S (2 days) | Depends on Phase 2 |
| 4 | CLI Tool | M (3-4 days) | Separate repo, no deps |
| 5 | Breach Monitoring | S (2-3 days) | No deps, can parallelize with 4 |

---

## Risks

- **E2EE complexity**: recovery codes và health scoring đều cần respect E2EE boundary
- **CLI v1 limitation**: không hỗ trợ E2EE vault (master password không nên truyền qua CLI args)
- **HIBP rate limits**: free tier = 1 req/1.5s — cần queue hoặc cache
- **Cross-repo impact**: Recovery codes + Health scoring đều ảnh hưởng API spec và có thể extension
