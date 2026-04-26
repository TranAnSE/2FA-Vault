# Phase 05 — Team Workflows

## Overview
Priority: Medium  
Status: Blocked by Phases 01, 03, and 04  
Make team sharing practical and auditable.

## Requirements
- Just-in-time team access with timed auto-revoke.
- Secret access request workflow.
- Passkey storage alongside TOTP if still in scope.
- Audit log UI and CSV export.
- Team roles: admin/editor/viewer.

## Related files
- team models/controllers/services
- account sharing policies
- audit log models/migrations
- frontend team views/stores
- API spec and docs

## Implementation steps
1. Audit current team model and role assumptions.
2. Add missing role semantics with policy tests.
3. Add audit log data model and event writes for team/account actions.
4. Build audit log UI and CSV export.
5. Add timed access grant/revoke workflow.
6. Add access request/approval workflow.
7. Reassess passkey storage scope separately before implementation.

## Success criteria
- Role permissions are enforced by backend policies.
- Audit log records who accessed/changed/shared what and when.
- Temporary access auto-revokes reliably.

## Risk assessment
- Team crypto/key-sharing can become complex; avoid changing vault format unless required.
- Timed access needs reliable server-side enforcement, not UI-only timers.

## Security considerations
- Audit log must not store plaintext secrets.
- Access revocation must be enforced at API layer.
