# Phase 04 — Account Hygiene

## Overview
Priority: Medium  
Status: Blocked by Phase 03  
Add features that help users manage large and aging vaults.

## Requirements
- Secret health dashboard.
- HIBP breach alert integration.
- Imports from Authy, Microsoft Authenticator, Bitwarden, 1Password, Aegis where feasible.
- Bulk operations: delete, move group, export.
- Search and filter for 100+ accounts.

## Related files
- account list views/stores/services
- import services/controllers
- dashboard components
- API routes if new backend endpoints are needed

## Implementation steps
1. Implement search/filter first because it improves all later account views.
2. Add bulk selection and move/delete operations.
3. Add health metrics from locally available metadata.
4. Add HIBP integration only after privacy implications are clear.
5. Add/import adapters one source at a time.
6. Update docs and API spec for new endpoints.

## Success criteria
- Account list remains usable with 100+ accounts.
- Bulk actions are safe and confirm destructive operations.
- Health dashboard avoids leaking sensitive data to third parties.

## Risk assessment
- HIBP lookups may leak account identifiers; require explicit user consent.
- Import formats vary; keep adapters isolated.

## Security considerations
- Do not send OTP secrets to external services.
- Breach checks must protect user privacy as much as possible.
