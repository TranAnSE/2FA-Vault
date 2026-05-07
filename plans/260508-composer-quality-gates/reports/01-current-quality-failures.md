# Current Quality Failures

Date: 2026-05-08

## Commands Observed

```bash
composer lint
composer analyse
composer test
```

## Passing Baseline

`composer test` passes:

- 1394 tests
- 4305 assertions
- 2 skipped
- 16 PHPUnit doc-comment metadata deprecations

## Lint Failure Summary

`composer lint` fails through `vendor/bin/pint --test`.

Observed categories:

- 218 style issues across 219 files.
- Dominant issue: `line_ending`.
- Other Pint rules seen: `unary_operator_spaces`, `not_operator_with_successor_space`, `blank_line_before_statement`, `ordered_imports`, `return_type_declaration`, `trailing_comma_in_multiline`, `phpdoc_trim`, `no_superfluous_phpdoc_tags`, `single_quote`, `class_definition`, `braces_position`, `binary_operator_spaces`.

Likely strategy:

- Use Pint auto-fix.
- Keep as separate mechanical formatting commit.
- Spot-check security-sensitive files after formatting.

## Analyse Failure Summary

`composer analyse` fails through PHPStan/Larastan with 121 errors.

Observed categories:

- Missing method return types and parameter types in controllers/factories/models.
- Undefined dynamic properties in API resources.
- Missing Eloquent relation type metadata in team/shared-account models.
- Missing generic type annotations for factories and `HasFactory`.
- Missing `Minishlink\WebPush` classes used by `PushSubscriptionController`.
- A few service/model-specific findings around relation inference and null coalescing.

Likely strategy:

- Resolve missing dependency/config noise first.
- Add native types and PHPDoc generics in focused groups.
- Avoid broad PHPStan ignores.

## Residual Questions

- Confirm whether push notifications are active product scope. If yes, add `minishlink/web-push`; if no, de-scope code and routes deliberately.

