# Phase 02 - Pint Formatting

## Context Links

- Parent plan: [plan.md](plan.md)
- Depends on: [phase-01-baseline-and-safety.md](phase-01-baseline-and-safety.md)
- Tooling: `vendor/bin/pint`

## Overview

- Date: 2026-05-08
- Description: Fix `composer lint` failures with minimal manual intervention.
- Priority: P1
- Implementation status: pending
- Review status: pending

## Key Insights

- Current lint failures are broad and mostly mechanical.
- `line_ending` issues likely came from mass text replacement and should be fixed consistently.
- Some Pint rules may change code formatting in files unrelated to the rename.

## Requirements

- Use Pint auto-fix where possible.
- Review non-line-ending changes before commit.
- Keep this phase as its own commit if it touches many files.

## Architecture

No runtime architecture changes.

## Related Code Files

- `app/**/*.php`
- `routes/**/*.php`
- `database/**/*.php`
- `tests/**/*.php` if Pint reports test files

## Implementation Steps

1. Run `vendor/bin/pint`.
2. Inspect `git diff --stat` and spot-check files with semantic-looking changes.
3. Run `composer lint`.
4. Run targeted tests if formatting touches executable paths.

## Todo List

- [ ] Run Pint auto-fix.
- [ ] Review formatting diff.
- [ ] Re-run `composer lint`.
- [ ] Confirm no accidental logic changes.

## Success Criteria

- `composer lint` exits 0.
- Diff is formatting-only.
- Commit is isolated from PHPStan semantic fixes.

## Risk Assessment

- Low runtime risk if Pint only formats.
- Medium review risk due file count.

## Security Considerations

- Formatting must not alter auth/encryption logic.
- Spot-check `EncryptionService`, `BackupService`, auth controllers, policies, and middleware if touched.

## Next Steps

Proceed to PHPStan config/dependency analysis.

