---
title: "Composer Quality Gates"
description: "Plan to make composer lint and composer analyse pass in the main Laravel app."
status: pending
priority: P1
effort: 12h
branch: master
tags: [quality, phpstan, pint, laravel]
created: 2026-05-08
---

# Composer Quality Gates

## Goal

Make `composer lint` and `composer analyse` pass in `2FA-Vault/` without changing runtime behavior.

## Current State

- `composer test` passes: 1394 tests, 4305 assertions, 2 skipped, 16 PHPUnit deprecations.
- `composer lint` fails with 218 style issues across 219 files, mostly `line_ending`, plus Pint rules such as spacing, import ordering, phpdoc trim, return type declarations, and blank lines.
- `composer analyse` fails with 121 PHPStan/Larastan issues, concentrated in missing return/parameter types, resource/model property inference, relation inference, missing WebPush symbols, and factory/model generic annotations.

## Phases

| Phase | Status | Progress | File |
| --- | --- | --- | --- |
| 01 - Baseline and Safety | pending | 0% | [phase-01-baseline-and-safety.md](phase-01-baseline-and-safety.md) |
| 02 - Pint Formatting | pending | 0% | [phase-02-pint-formatting.md](phase-02-pint-formatting.md) |
| 03 - PHPStan Config and Dependencies | pending | 0% | [phase-03-phpstan-config-and-dependencies.md](phase-03-phpstan-config-and-dependencies.md) |
| 04 - Type and Relation Fixes | pending | 0% | [phase-04-type-and-relation-fixes.md](phase-04-type-and-relation-fixes.md) |
| 05 - Validation and Commit Split | pending | 0% | [phase-05-validation-and-commit-split.md](phase-05-validation-and-commit-split.md) |

## Reports

- [reports/01-current-quality-failures.md](reports/01-current-quality-failures.md)

## Validation Gates

Run from `2FA-Vault/`:

```bash
composer lint
composer analyse
composer test
npm run build
```

## Sibling Repos

No sibling repo changes are expected unless PHP runtime contract changes affect OpenAPI/docs. This plan should stay main-app only.

