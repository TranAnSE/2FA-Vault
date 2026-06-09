---
title: Security Hardening
description: Comprehensive security hardening across CORS, sessions, database, rate limiting, backups, CSP, and encryption disable safety
status: completed
priority: high
effort: L
branch: main
tags: [security, hardening, cors, session, encryption, csp, rate-limiting]
created: 2026-06-09
---

# Security Hardening

## Overview

7-phase security hardening pass covering CORS configuration, session encryption, database performance indexes, rate limiting, backup encryption, CSP extension support, and encryption-disable safety.

## Phases

| # | Phase | Status | Key Changes |
|---|-------|--------|-------------|
| 1 | CORS Hardening | Complete | `CORS_MAX_AGE` default 0 -> 86400, configurable methods/origins/headers |
| 2 | Session Encryption | Complete | `SESSION_ENCRYPT` default false -> true, server-side session payload encryption |
| 3 | Database Indexes | Complete | Migration `2026_06_09_120000` adding indexes on twofaccounts (user_id, order_column, group_id, last_used_at, encrypted) and groups (user_id) |
| 4 | Rate Limiting | Complete | `AuthRateLimitTest` covering login/register/password-reset throttle |
| 5 | Backup Encryption | Complete | BackupService and BackupController enforce encrypted backup export |
| 6 | CSP Extension | Complete | `ContentSecurityPolicyMiddlewareTest` covering extension frame/worker allowances |
| 7 | Encryption Disable Safety | Complete | `EncryptionServiceDisableTest` + `EncryptionControllerDisableTest` ensuring graceful handling when encryption is disabled |

## Key Files

- `config/cors.php` — CORS_MAX_AGE default 86400
- `config/session.php` — SESSION_ENCRYPT default true
- `database/migrations/2026_06_09_120000_add_missing_indexes_to_core_tables.php`
- `tests/Feature/Http/Auth/AuthRateLimitTest.php`
- `tests/Feature/Services/BackupServiceTest.php`
- `tests/Feature/Http/Middlewares/ContentSecurityPolicyMiddlewareTest.php`
- `tests/Feature/Services/EncryptionServiceDisableTest.php`
- `tests/Feature/Http/Controllers/EncryptionControllerDisableTest.php`

## Test Impact

34 new security-focused tests added. 1428 total tests passing.

## Breaking Changes

- `SESSION_ENCRYPT` default changed from `false` to `true` — existing sessions invalidated after upgrade
- `CORS_MAX_AGE` default changed from `0` to `86400` — preflight responses cached for 24h
