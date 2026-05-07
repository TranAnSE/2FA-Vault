# Phase 03 - PHPStan Config and Dependencies

## Context Links

- Parent plan: [plan.md](plan.md)
- Depends on: [phase-02-pint-formatting.md](phase-02-pint-formatting.md)
- Tooling: `phpstan.neon`, `composer.json`

## Overview

- Date: 2026-05-08
- Description: Fix analysis failures caused by missing dependencies or missing framework metadata before editing many classes.
- Priority: P1
- Implementation status: pending
- Review status: pending

## Key Insights

- PHPStan reports `Minishlink\WebPush\WebPush` and `Minishlink\WebPush\Subscription` as missing.
- App code references push notification behavior, but `composer.json` does not currently require `minishlink/web-push`.
- Some model relation/property errors may be resolved by adding precise PHPDoc instead of ignoring rules.

## Requirements

- Decide whether WebPush code is active product behavior or dead code.
- If active, add the correct Composer dependency and update lockfile.
- Avoid broad `ignoreErrors` unless an issue is framework-noise and documented.

## Architecture

This phase may affect dependency graph only. It should not change API behavior.

## Related Code Files

- `composer.json`
- `composer.lock`
- `phpstan.neon`
- `app/Http/Controllers/PushSubscriptionController.php`
- `app/Models/PushSubscription.php`

## Implementation Steps

1. Confirm PushSubscription routes and tests are active.
2. Check compatible `minishlink/web-push` version for PHP 8.4 and Laravel 12.
3. Add dependency if active, or remove/de-scope dead code only if product confirms.
4. Add PHPStan config only for well-understood framework gaps.
5. Re-run `composer analyse` and compare issue count.

## Todo List

- [ ] Inspect push subscription routes/tests.
- [ ] Decide WebPush dependency vs dead-code removal.
- [ ] Update dependency/config as needed.
- [ ] Re-run PHPStan.

## Success Criteria

- Missing WebPush class errors are resolved.
- PHPStan config remains strict and understandable.
- Any new dependency is justified and tested.

## Risk Assessment

- Medium dependency risk if WebPush version pulls transitive packages.
- Low runtime risk if only active dependency is added.

## Security Considerations

- WebPush VAPID/key handling must not expose secrets.
- Do not suppress auth/policy errors via generic ignore rules.

## Next Steps

Proceed to typed code fixes after dependency/config noise is reduced.

