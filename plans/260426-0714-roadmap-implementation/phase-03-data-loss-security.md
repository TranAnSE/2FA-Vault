# Phase 03 — Data Loss Prevention and Security Basics

## Overview
Priority: High  
Status: Blocked by Phase 00  
Reduce risk of account loss and improve self-host security baseline.

## Requirements
- Printable recovery codes or Shamir recovery, ordered by simplest reliable fallback first.
- `php artisan vault:backup` export command.
- `php artisan vault:restore <file>` restore command.
- Login rate limiting and account lockout.
- Security headers middleware.
- Email verification.
- Review password reset flow against OWASP ASVS.
- Dependabot/security advisory setup.

## Related files
- `app/Console/Commands/`
- backup services/controllers
- auth controllers/middleware/config
- tests under `tests/Feature/` and `tests/Unit/Services/`
- docs and README deployment/security sections

## Implementation steps
1. Split recovery into baseline printable recovery codes first; defer Shamir if over-scoped.
2. Add backup CLI using existing backup service where possible.
3. Add restore CLI with dry-run/integrity validation if supported by current service boundaries.
4. Add rate limiting and lockout tests.
5. Add one security headers middleware.
6. Enable email verification if compatible with current auth flow.
7. Review password reset flow and patch gaps.
8. Update docs.

## Success criteria
- User can export and restore encrypted vault from CLI.
- Login brute-force path is rate-limited/locked out.
- Security headers are present in HTTP responses.
- Recovery docs are clear about what is and is not recoverable.

## Risk assessment
- Recovery features can weaken E2EE if designed poorly.
- Restore can overwrite user data; require explicit confirmation/dry-run behavior.

## Security considerations
- Recovery material must be generated client-side or protected equivalently.
- No plaintext secrets in CLI output.
