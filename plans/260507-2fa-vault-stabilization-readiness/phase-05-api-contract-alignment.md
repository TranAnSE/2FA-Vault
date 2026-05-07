---
phase: 5
title: "API Contract Alignment"
status: pending
priority: P1
effort: "1.5d"
dependencies: [2]
---

# Phase 5: API Contract Alignment

## Overview

Bring `2FA-Vault-API` in line with the fork's implemented endpoints and supported feature state.

## Requirements

- Functional: OpenAPI documents encrypted account endpoint, encryption metadata, team APIs if implemented, errors, auth modes, and examples.
- Non-functional: contract must match actual Laravel routes and resources.

## Architecture

OpenAPI is the source for external clients. It must reflect JSON resources in `2FA-Vault/app/Api/v1/Resources` and routes in `routes/api/v1.php`.

## Related Code Files

- Modify: `D:/2FA-Vault/2FA-Vault-API/2fauth-api-latest.yaml`
- Modify: `D:/2FA-Vault/2FA-Vault-API/changelog.md`
- Modify: `D:/2FA-Vault/2FA-Vault-API/README.md`
- Read: `D:/2FA-Vault/2FA-Vault/routes/api/v1.php`
- Read: `D:/2FA-Vault/2FA-Vault/app/Api/v1/Resources/**`

## Implementation Steps

1. Diff implemented Laravel routes against OpenAPI paths.
2. Define encrypted payload schemas: `ciphertext`, `iv`, `authTag`, `encrypted`, salt/test value metadata.
3. Document auth requirements: session, PAT, Passport bearer token.
4. Add examples for encrypted account list and encryption status.
5. Add or explicitly exclude team endpoints based on implementation maturity.
6. Update API README from upstream 2FAuth wording to fork-aware wording.
7. Run OpenAPI validation and rapidoc render check.

## Success Criteria

- [ ] OpenAPI validates.
- [ ] `/api/v1/twofaccounts/encrypted` contract matches app and extension.
- [ ] README/changelog clearly identify 2FA-Vault fork state.
- [ ] Unsupported/experimental endpoints are labeled, not implied stable.

## Risk Assessment

Risk: API docs get ahead of code. Mitigation: every documented endpoint must map to a route/test or be marked experimental.
