---
phase: 3
title: "Core App E2EE Hardening"
status: pending
priority: P1
effort: "3d"
dependencies: [2]
---

# Phase 3: Core App E2EE Hardening

## Overview

Harden the main Laravel/Vue app around E2EE, backup, teams, and security-critical behavior. The server must never decrypt client-side encrypted secrets.

## Requirements

- Functional: E2EE setup/unlock/account CRUD/backup/export/import flows verified.
- Non-functional: zero-knowledge invariant protected by automated tests; no secrets in logs; backward compatibility with non-encrypted accounts.

## Architecture

Client derives key with Argon2id and uses AES-GCM. Server stores ciphertext payloads and metadata only. Backend policies/services must branch on `encrypted` without decrypting.

## Related Code Files

- Modify: `D:/2FA-Vault/2FA-Vault/app/Http/Controllers/EncryptionController.php`
- Modify: `D:/2FA-Vault/2FA-Vault/app/Services/EncryptionService.php`
- Modify: `D:/2FA-Vault/2FA-Vault/app/Services/TwoFAccountService.php`
- Modify: `D:/2FA-Vault/2FA-Vault/app/Services/BackupService.php`
- Modify: `D:/2FA-Vault/2FA-Vault/app/Services/TeamService.php`
- Modify: `D:/2FA-Vault/2FA-Vault/resources/js/services/crypto.js`
- Modify: `D:/2FA-Vault/2FA-Vault/resources/js/stores/crypto.js`
- Modify: `D:/2FA-Vault/2FA-Vault/resources/js/stores/twofaccounts.js`
- Modify: `D:/2FA-Vault/2FA-Vault/tests/**`

## Implementation Steps

1. Before edits, run `gitnexus_impact` for each symbol to modify and record blast radius.
2. Audit encryption setup/unlock payloads: salt, test value, ciphertext shape, error handling.
3. Audit account create/update/list/export paths for `encrypted` flag correctness.
4. Add regression tests for plaintext baseline, encrypted account CRUD, and server-side non-decryption.
5. Add backup restore tests for encrypted vault data, duplicate policies, and wrong password errors.
6. Audit team sharing code for authorization boundaries and future E2EE sharing assumptions.
7. Remove or downgrade false feature claims in app-local docs until tests prove behavior.
8. Run PHP/Vite/E2E gates and `gitnexus_detect_changes`.

## Success Criteria

- [ ] Zero-knowledge E2E tests pass.
- [ ] Encrypted account data is never decrypted server-side.
- [ ] Backup import/export preserves encrypted payloads correctly.
- [ ] Team APIs enforce ownership/member role boundaries.
- [ ] No logs expose OTP secrets, PATs, vault keys, ciphertext keys, or master passwords.

## Risk Assessment

Risk: E2EE sharing for teams can become over-engineered. Mitigation: stabilize single-user E2EE first; document team E2EE as explicit beta/roadmap unless fully implemented.
