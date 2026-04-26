# Phase 02 — Extension Daily Driver

## Overview
Priority: High  
Status: Blocked by Phase 01  
Turn the extension from a copy helper into a practical autofill assistant.

## Requirements
- Detect OTP input fields in content scripts.
- Match accounts to current domain.
- Autofill OTP only after user action.
- Add quick-add from QR codes on current page.
- Add keyboard shortcut.
- Add WebAuthn/platform unlock if feasible after Phase 01.

## Related repos/files
Primary:
- `D:/2FA-Vault/2FA-Vault-WebExtension`

Secondary if API changes:
- `D:/2FA-Vault/2FA-Vault-API`
- `D:/2FA-Vault/2FA-Vault-Docs`

## Implementation steps
1. Define OTP field detection heuristics and test pages.
2. Implement content-script detection with no automatic form submission.
3. Implement issuer/label/domain matching.
4. Add popup suggestion UI.
5. Add user-triggered autofill.
6. Add quick-add QR detection if it does not require unsafe page access.
7. Add extension E2E tests.
8. Update user docs.

## Success criteria
- User can unlock extension and fill OTP into common login pages.
- Extension never auto-submits login forms.
- Domain matching is explainable and overridable.

## Risk assessment
- Autofill can be invasive; keep user-triggered.
- QR detection may be brittle; isolate as separate slice.

## Security considerations
- Content script should get minimum required data.
- Never expose full vault to arbitrary pages.
