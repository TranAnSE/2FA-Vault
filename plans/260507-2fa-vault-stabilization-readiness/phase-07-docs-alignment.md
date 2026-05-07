---
phase: 7
title: "Docs Alignment"
status: pending
priority: P2
effort: "2d"
dependencies: [2]
---

# Phase 7: Docs Alignment

## Overview

Make docs honest and useful for the actual fork state. Separate upstream 2FAuth capability from 2FA-Vault experimental capability.

## Requirements

- Functional: docs cover install, upgrade/migration, E2EE, backup, extension, API, teams status, troubleshooting.
- Non-functional: no false production claims; mark beta/experimental clearly.

## Architecture

Docs exist in two places:

- Internal/project docs: `D:/2FA-Vault/2FA-Vault/docs`
- Public docs repo: `D:/2FA-Vault/2FA-Vault-Docs/docs`

Internal docs guide contributors. Public docs guide users/admins.

## Related Code Files

- Modify: `D:/2FA-Vault/2FA-Vault/README.md`
- Modify: `D:/2FA-Vault/2FA-Vault/docs/**`
- Modify: `D:/2FA-Vault/2FA-Vault-Docs/docs/**`
- Modify: `D:/2FA-Vault/2FA-Vault-Docs/README.md`
- Modify: `D:/2FA-Vault/2FA-Vault-Docs/retype.yml`

## Implementation Steps

1. Create docs status matrix: stable, beta, experimental, not implemented.
2. Update top-level README with exact fork readiness and supported workflows.
3. Update E2EE docs with real client/server responsibilities and recovery limitations.
4. Update extension docs with setup, lock/unlock, local OTP behavior, browser compatibility.
5. Update API docs links to fork API spec.
6. Update migration docs from upstream 2FAuth to 2FA-Vault.
7. Run docs build and link checks.

## Success Criteria

- [ ] User can install and run dev/prod without guessing repo relationships.
- [ ] Docs no longer imply complete teams/E2EE extension sync unless verified.
- [ ] Public docs build succeeds.
- [ ] Internal docs match actual code and tests.

## Risk Assessment

Risk: docs become aspirational. Mitigation: every feature page must include status and tested version/date.
