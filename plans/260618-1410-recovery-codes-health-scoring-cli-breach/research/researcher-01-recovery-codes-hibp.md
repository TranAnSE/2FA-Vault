# Research Report: Recovery Codes & HIBP Integration

**Date:** 2026-06-18  
**Researcher:** Claude Haiku 4.5  
**Status:** Complete

---

## Executive Summary

Recovery codes and HIBP integration are critical security components for 2FA systems. Industry leaders (GitHub, GitLab) use **bcrypt hashing** for recovery codes stored in separate tables, not reversible encryption. HIBP API v3 offers k-anonymity for privacy-preserving breach checks with free password API and tiered rate limits for authenticated endpoints. Laravel HTTP client supports caching via middleware, enabling efficient HIBP integration without PII exposure.

---

## Key Findings

### 1. Recovery Codes Storage Patterns

**Hashing vs. Encryption Decision:**
- **Industry standard: Hashing (bcrypt)**, not encryption
- GitHub & GitLab use bcrypt-hashed recovery codes [1][2][7]
- Rationale: recovery codes are rarely displayed after initial setup; hashing provides one-way security even if DB is compromised
- If users need to view codes later, encrypt the codes and hash them for comparison; this adds complexity but preserves view-once UX

**Storage Model:**
- **Separate table recommended** over JSON array in user record
- GitHub format: 10 alphanumeric codes (xxxxx-yyyyy) [7]
- GitLab format: multiple codes, single-use only [2]
- Separate table enables:
  - Efficient indexing for "code already used?" checks
  - Audit trail (created_at, used_at timestamps)
  - Integrity constraints (cannot reuse, foreign key to user)

**One-Time Use Enforcement:**
- Mark used codes with `used_at` timestamp and check before validation
- Database constraint ensures no duplicates per user
- Audit trail helps detect brute-force attempts

### 2. HaveIBeenPwned (HIBP) API v3 Integration

**K-Anonymity Model (Password API):**
- Client-side hashing: SHA-1 hash of password, send first 5 characters only [4][6]
- Server returns range of all matching suffix hashes (no need for API key)
- Client compares locally → zero exposure of actual password or full hash
- No authentication required; no hard rate limit on free tier [4]

**Breach Lookup (Email API):**
- Requires API key and authentication [4]
- K-anonymity available: SHA-1 of email, send 6-char prefix [4]
- Rate-limited: free tier has strict limits; subscription tiers offer 1K–10K+ requests/min [5]
- Returns breach names, dates, compromised data types

**Rate Limits:**
- Free password API: No hard limit (but implicit throttling) [4]
- Authenticated endpoints: Varies by tier; 429 Too Many Requests returns Retry-After header [5]
- Paid API keys: Annual billing model with published rate limits per subscription level [5]

**Privacy Considerations:**
- **Never send full password hash or plaintext to HIBP**
- Range endpoint design ensures HIBP cannot reverse-engineer searches
- Email k-anonymity only reveals 6-char prefix to server
- Cache responses locally to reduce API calls (complies with terms)

### 3. Laravel Implementation Patterns

**HTTP Client Caching:**
- Laravel HTTP client supports cache middleware (via `Illuminate\Http\Client\PendingRequest::cache()`)
- Store HIBP responses in Redis or file cache with TTL (24 hours typical)
- Example: Cache breach list by name; cache "not pwned" states for 30 days

**Typical Workflow:**
1. User enters password → Client hashes (SHA-1) → Send 5-char prefix
2. Receive range → Client does local suffix check
3. Cache result: "password OK" or "password in X breaches"
4. For email checks: Hash email → 6-char prefix → Cache breach records

---

## Best Practices Summary

| Aspect | Recommendation | Rationale |
|--------|---|---|
| Recovery Code Storage | Bcrypt-hashed in separate table | Industry standard; supports audit trail; one-use enforcement |
| Table Structure | `recovery_codes(id, user_id, code_hash, used_at, created_at)` | Enables queries for reuse check & audit |
| HIBP Password API | Range endpoint with 5-char SHA-1 prefix | Free, no auth, zero PII exposure |
| HIBP Email API | Requires key; use 6-char k-anonymity prefix | Paid but cost-effective for bulk checks |
| Caching | Redis/File cache, TTL 24h (breach), 30d ("not pwned") | Reduces API calls; complies with HIBP ToS |
| Rate Limit Handling | Retry-After header + exponential backoff | Built into Laravel HTTP client |

---

## Implementation Considerations

**Gotchas:**
1. Recovery code display is one-time only; users who miss download must regenerate via 2FA reset
2. HIBP range endpoint returns ~500 hashes per query; client-side filtering required
3. Email k-anonymity returns false positives if prefix collision; verify with full hash locally if needed
4. Cache invalidation: HIBP publishes new compromises daily; respect 24h cache window

**Security Checklist:**
- ✅ Recovery codes hashed with bcrypt cost ≥12
- ✅ No plaintext recovery codes in logs or error messages
- ✅ HIBP requests use HTTPS only
- ✅ Cache responses have strict TTL; no unbounded storage
- ✅ Audit trail captures code generation & usage

---

## Sources

1. [How to Store Your 2FA Backup Codes Securely - Kolide](https://www.kolide.com/blog/how-to-store-your-2fa-backup-codes-securely)
2. [Two-factor authentication · GitLab Docs](https://docs.gitlab.com/ee/user/profile/account/two_factor_authentication.html)
3. [Configuring 2FA recovery methods - GitHub Docs](https://docs.github.com/en/authentication/securing-your-account-with-two-factor-authentication-2fa/configuring-two-factor-authentication-recovery-methods)
4. [Have I Been Pwned API v3 Documentation](https://haveibeenpwned.com/API/v3)
5. [HIBP Rate Limits & Annual Billing - Troy Hunt](https://www.troyhunt.com/the-have-i-been-pwned-api-now-has-different-rate-limits-and-annual-billing/)
6. [HIBP K-Anonymity Searches Update - Troy Hunt](https://www.troyhunt.com/passkeys-k-anonymity-searches-massive-speed-enhancements-bulk-domain-verification-api/)
7. [GitHub Recovery Code Guide - GitProtect](https://gitprotect.io/blog/github-recovery-code/)

---

## Unresolved Questions

1. Should recovery codes be re-encrypted after bcrypt hash for view capability, or accept one-time-display-only UX?
2. What is optimal bcrypt cost factor for recovery codes? (GitHub/GitLab don't publish)
3. For email breach checks, should app queue async HIBP lookups or do sync checks with timeout?
4. HIBP API docs don't specify max requests per day on free password API; should we hard-cap or rely on throttling?
