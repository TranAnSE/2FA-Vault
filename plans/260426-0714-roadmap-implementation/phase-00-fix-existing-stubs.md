# Phase 00 — Fix Existing Stubs

## Context links
- Roadmap: `docs/reference/roadmap.md`
- Service worker: `public/sw.js`
- Crypto service: `resources/js/services/crypto.js`
- API routes: `routes/api/v1.php`

## Overview
Priority: Critical  
Status: Ready  
Fix features that currently claim to work but are stubbed or broken.

## Requirements
- Replace hardcoded service-worker TOTP output with real RFC 6238 generation.
- Fix service-worker dynamic import issue.
- Add HTTP E2EE round-trip coverage.
- Correct docs/claims that say background sync or seamless extension access is implemented.

## Related code files
Modify:
- `public/sw.js`
- `CLAUDE.md`
- likely `docs/architecture/project-overview-pdr.md`
- likely `docs/architecture/system-architecture.md`
- targeted tests under `tests/Feature/` or `tests/Api/v1/`

## Implementation steps
1. Inspect `public/sw.js` around the dynamic import and `generateTOTP()` stub.
2. Run GitNexus impact before modifying service-worker symbols.
3. Implement minimal browser-compatible TOTP generation in the service worker.
4. Remove unsupported `import()` usage or replace with service-worker-safe loading.
5. Add tests for E2EE HTTP flow: setup key metadata, create encrypted account, fetch encrypted payload unchanged.
6. Update false claims in project docs.
7. Validate with targeted PHPUnit, `npm run build`, and browser/service-worker smoke test.
8. Check sibling docs/API impact and update if needed.

## Success criteria
- No hardcoded `000000` OTP path remains.
- Service worker loads without runtime import error.
- E2EE HTTP tests assert ciphertext remains server-side opaque.
- Docs no longer claim background sync or seamless extension access as complete.

## Risk assessment
- Service worker APIs differ from window APIs; use Web Crypto and standard browser primitives only.
- Avoid introducing npm dependency unless necessary.

## Security considerations
- Never persist plaintext secrets outside the intended offline encrypted cache flow.
- Never log OTP secrets, derived keys, ciphertext, or master passwords.
