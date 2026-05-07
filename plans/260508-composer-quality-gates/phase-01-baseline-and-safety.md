# Phase 01 - Baseline and Safety

## Context Links

- Parent plan: [plan.md](plan.md)
- Workspace docs: `D:/2FA-Vault/docs/codebase-summary.md`, `D:/2FA-Vault/docs/code-standards.md`, `D:/2FA-Vault/docs/system-architecture.md`
- Main app config: `composer.json`, `phpstan.neon`, `phpunit.xml`

## Overview

- Date: 2026-05-08
- Description: Capture precise quality failures and separate real defects from formatting noise.
- Priority: P1
- Implementation status: pending
- Review status: pending

## Key Insights

- `composer test` currently passes, so type/lint cleanup must preserve behavior.
- Lint output is noisy because line endings dominate the failure list.
- PHPStan errors include both true missing types and framework inference gaps.

## Requirements

- Run `composer lint`, `composer analyse`, and `composer test` from a clean worktree.
- Save full failure output in `reports/`.
- Categorize PHPStan issues by owner file group and fix strategy.

## Architecture

No architecture changes. This phase is evidence gathering and risk control.

## Related Code Files

- `composer.json`
- `phpstan.neon`
- `app/`
- `database/factories/`
- `routes/`

## Implementation Steps

1. Confirm no unrelated dirty files before starting.
2. Run `composer lint` and save full output to `reports/02-lint-baseline.md`.
3. Run `composer analyse` and save full output to `reports/03-phpstan-baseline.md`.
4. Run `composer test` to confirm runtime baseline.
5. Create a categorized issue matrix.

## Todo List

- [ ] Capture current lint output.
- [ ] Capture current analyse output.
- [ ] Confirm tests pass before edits.
- [ ] Identify generated/vendor-style files to avoid editing.

## Success Criteria

- Baseline reports exist.
- Each failure class has a planned fix approach.
- No code changes yet except reports.

## Risk Assessment

- Low runtime risk.
- Medium process risk if line-ending churn obscures meaningful changes.

## Security Considerations

- No security behavior changes.
- Do not loosen PHPStan for security-sensitive auth/encryption paths without justification.

## Next Steps

Proceed to Pint formatting only after baseline is documented.

