# Phase 05 - Validation and Commit Split

## Context Links

- Parent plan: [plan.md](plan.md)
- Depends on: [phase-04-type-and-relation-fixes.md](phase-04-type-and-relation-fixes.md)

## Overview

- Date: 2026-05-08
- Description: Validate quality gates and prepare reviewable commits.
- Priority: P1
- Implementation status: pending
- Review status: pending

## Key Insights

- The final output should avoid mixing mechanical formatting with PHPStan semantic typing.
- Test coverage already passes, so regression detection should focus on changed domains.

## Requirements

- Run complete validation before final report.
- Split commits by mechanical formatting, dependency/config, and typed code fixes.
- Update plan statuses after implementation.

## Architecture

No architecture changes.

## Related Code Files

- All files changed in phases 02-04.

## Implementation Steps

1. Run `composer lint`.
2. Run `composer analyse`.
3. Run `composer test`.
4. Run `npm run build` if PHP changes affect frontend-served config or generated assets.
5. Review `git diff --stat` and commit in logical groups.

## Todo List

- [ ] `composer lint`
- [ ] `composer analyse`
- [ ] `composer test`
- [ ] `npm run build`
- [ ] Commit split review

## Success Criteria

- All required commands pass.
- Commits are small enough to review.
- Final report lists touched domains, tests, and residual risks.

## Risk Assessment

- Low runtime risk after full test suite.
- Medium review risk if Pint touches many files.

## Security Considerations

- Include focused review of auth, encryption, team sharing, PAT, and backup files if they are touched.

## Next Steps

Ask for approval to implement the plan.

