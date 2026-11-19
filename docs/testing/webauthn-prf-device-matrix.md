# WebAuthn PRF device matrix

Plan 261119-1650 phase 4. Converts the theoretically-documented authenticator
caveats (YubiKey <5.8 double-UV prompt at PRF enroll, Windows Hello PRF
support, hybrid phone passkeys) into observed, recorded fact. The v1.7.0 e2e
suite only ever exercised a CDP virtual authenticator — this matrix is the
manual counterpart.

**Status legend:** PASS / PASS-WITH-CAVEAT / FAIL / NOT-AVAILABLE

**Non-gating:** this matrix is opportunistic — it never blocks the cycle's
release (plan phase 7 NOTE). Record runs honestly; "no device available" is a
valid recorded state, but at least one physical class must be attempted before
the phase can close as anything but docs-only.

## Setup (per device run)

1. Local secure context: WebAuthn needs `localhost` or HTTPS. Serve the app
   on `http://localhost:8000` and browse from `http://localhost:8000`
   (NOT 127.0.0.1 — Chromium binds rp.id to the page host, and an IP-host
   page cannot use an `localhost` rp.id).

   ```bash
   php artisan serve
   # browse http://localhost:8000
   ```

2. Seeded test user with E2EE enabled: fresh install, register
   `matrix@2fauth.app` / `password`, then Settings → Encryption → set master
   password `MasterPass123!` (the same constant the e2e suites use).

3. Per-class recording form (copy per run):

   ```
   Date:            ____-__-__
   Browser + OS:    ______ (e.g. Chrome 142 / Windows 11 24H2)
   Device:          ______ (model + firmware where applicable)
   Class:           ______
   Register passkey:          [ ] OK  [ ] caveat/notes: ______
   prf-at-creation accepted:  [ ] OK  [ ] not offered  [ ] notes: ______
   Enroll vault unlock:       [ ] OK  [ ] double UV prompt  [ ] notes: ______
   Lock vault:                [ ] OK  [ ] notes: ______
   Passkey unlock:            [ ] OK  [ ] notes: ______
   Rotate (re-wrap):          [ ] OK  [ ] notes: ______
   Passkey unlock (post-rotate): [ ] OK  [ ] notes: ______
   Revoke passkey:            [ ] OK  [ ] notes: ______
   Verdict:         ______
   ```

## 1. YubiKey firmware ≥ 5.8

Single UV prompt expected at PRF enroll (hmac-secret evaluated under the
same touch/UV ceremony). Known-good reference class.

| Step | Expected | Observed | Verdict |
|------|----------|----------|---------|
| Register passkey | Single touch + UV | not run | NOT-AVAILABLE |
| prf-at-creation accepted | advertised + accepted | not run | — |
| Enroll vault unlock | Single UV prompt | not run | — |
| Lock → passkey unlock | Single UV | not run | — |
| Rotate → passkey unlock | Re-wrap lands; unlock works | not run | — |
| Revoke | Enrollment cascades | not run | — |

## 2. YubiKey firmware < 5.8

Documented caveat: TWO UV prompts at enroll (hmac-secret evaluate issued as a
second ceremony). Whether the double prompt is still observed on current
browsers is the point of this class.

| Step | Expected | Observed | Verdict |
|------|----------|----------|---------|
| Register passkey | Touch + UV | not run | NOT-AVAILABLE |
| prf at creation | advertised | not run | — |
| Enroll vault unlock | **two** UV prompts (the caveat under test) | not run | — |
| Lock → passkey unlock | Single UV | not run | — |
| Rotate → unlock | OK | not run | — |
| Revoke | OK | not run | — |

## 3. Windows Hello (Chrome + Edge)

Unknown-until-run: does Windows Hello's TPM-backed passkey advertise/execute
`prf` at creation and at assertion, with a single UV (PIN/biometric) at
enroll and unlock?

| Step | Expected | Observed | Verdict |
|------|----------|----------|---------|
| Register passkey (Chrome) | Windows Hello prompt (PIN/biometric) | not run | NOT-AVAILABLE |
| prf-at-creation accepted | accepted; passkey unlock becomes available | not run | — |
| Enroll vault unlock | single UV (PIN) | not run | — |
| Lock → passkey unlock | single UV | not run | — |
| Rotate → passkey unlock | OK | not run | — |
| Revoke | OK | not run | — |

## 4. macOS Touch ID

| Step | Expected | Observed | Verdict |
|------|----------|----------|---------|
| Full flow (register → enroll → unlock → rotate → revoke) | Touch ID prompt, single UV, prf advertised | not run | NOT-AVAILABLE |

## 5. Android/iPhone hybrid (QR cross-device)

| Step | Expected | Observed | Verdict |
|------|----------|----------|---------|
| Register via QR cross-device | phone completes the ceremony | not run | NOT-AVAILABLE |
| prf over hybrid | per docs, prf NOT relayed over hybrid | not run | — |

> Caveat note (public docs): the hybrid (QR cross-device) flow does not relay
> the `prf` extension — a phone-completed ceremony cannot enroll PRF unlock.
> This caveat is DOCUMENTED but NOT OBSERVED; it stays as-is in the public
> docs until a hybrid run records different prf-relay behavior.

## Recorded runs (journal)

| Date | Class | Browser/OS | Verdict |
|------|-------|------------|---------|
| 2026-11-20 | (none — docs-only gate chosen) | — | No physical device could be driven this cycle: YubiKeys / phones / macOS absent from the test fleet, and Windows Hello requires interactive PIN/biometric entry that the automation environment cannot complete. |

**Phase verdict: docs-only gate, explicitly chosen and journaled.** The
matrix + recording form ship as the deliverable; physical execution remains
opportunistic and user-owned (≥1 physical class attempted is still required
before any *future* closure as executed-with-coverage).
