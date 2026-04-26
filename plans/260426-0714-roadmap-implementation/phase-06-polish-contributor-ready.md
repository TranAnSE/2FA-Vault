# Phase 06 — Polish and Contributor Ready

## Overview
Priority: Medium  
Status: Blocked by Phases 02 and 05  
Make the project trustworthy and easy to self-host/contribute to.

## Requirements
- Dark/light/auto theme.
- Mobile responsive pass on real devices.
- Basic keyboard navigation and accessibility.
- Soft delete with 7-day trash and undo.
- i18n EN + VI.
- GDPR export/delete account.
- Contributor docs and templates.
- Pre-commit hooks.
- Conventional commits and changelog automation.
- Docker Compose one-command self-host path.

## Related files
- frontend UI/theme/layout files
- account deletion flows
- i18n resources
- project root docs/templates/config
- Docker files

## Implementation steps
1. Add contributor docs/templates with minimal useful content.
2. Add pre-commit hooks for existing lint/test commands.
3. Implement theme support using existing design tokens/components.
4. Run mobile/responsive pass on core flows.
5. Add basic a11y fixes.
6. Add soft delete/trash workflow.
7. Add EN/VI i18n coverage.
8. Add GDPR export/delete flows.
9. Validate Docker Compose fresh install under 5 minutes.

## Success criteria
- New contributor can install, run, test, and open PR with documented workflow.
- Self-host path works from clean checkout.
- Core UI works on mobile and keyboard.

## Risk assessment
- This phase can sprawl; keep each item a separate slice.
- Soft delete touches data lifecycle and must be tested carefully.

## Security considerations
- Account delete/export must require authentication and clear confirmation.
- Docker defaults must not ship weak secrets.
