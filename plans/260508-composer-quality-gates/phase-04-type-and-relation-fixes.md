# Phase 04 - Type and Relation Fixes

## Context Links

- Parent plan: [plan.md](plan.md)
- Depends on: [phase-03-phpstan-config-and-dependencies.md](phase-03-phpstan-config-and-dependencies.md)
- Standards: `D:/2FA-Vault/docs/code-standards.md`

## Overview

- Date: 2026-05-08
- Description: Fix remaining PHPStan/Larastan errors with explicit return types, relation types, resource annotations, and factory generics.
- Priority: P1
- Implementation status: pending
- Review status: pending

## Key Insights

- Reported hotspots include `TeamController`, team models, push models/controllers, API resources, `TwoFAccount`, factories, and `BackupService`.
- Many issues are missing type declarations rather than behavior bugs.
- Some Larastan relation errors indicate missing or hard-to-infer model relation signatures.

## Requirements

- Add native return/parameter types where unambiguous.
- Add Eloquent relation return types with generics in PHPDoc where needed.
- Annotate resources with `@mixin` or resource-specific PHPDoc when Laravel dynamic properties are intended.
- Keep behavior-preserving tests green after each hotspot group.

## Architecture

No feature architecture change. This phase improves static contract clarity across existing controllers, models, resources, factories, and services.

## Related Code Files

- `app/Api/v1/Controllers/TwoFAccountController.php`
- `app/Api/v1/Resources/*Resource.php`
- `app/Http/Controllers/Admin/UserManagementController.php`
- `app/Http/Controllers/PushSubscriptionController.php`
- `app/Http/Controllers/TeamController.php`
- `app/Models/Team.php`, `TeamInvitation.php`, `TeamMember.php`, `SharedAccount.php`, `PushSubscription.php`, `TwoFAccount.php`, `User.php`
- `app/Services/BackupService.php`
- `database/factories/*.php`

## Implementation Steps

1. Fix controller method signatures in one commit group.
2. Fix model relations and PHPDoc generics.
3. Fix API resource dynamic property annotations.
4. Fix factories with generic `@extends Factory<ModelClass>` annotations and return types.
5. Fix remaining `TwoFAccount`, `User`, and `BackupService` findings.
6. Run focused PHPUnit tests for changed domains.

## Todo List

- [ ] Controller signatures.
- [ ] Eloquent relation types.
- [ ] Resource annotations.
- [ ] Factory generics.
- [ ] Remaining service/model PHPStan findings.
- [ ] Focused test runs.

## Success Criteria

- `composer analyse` exits 0.
- `composer test` remains green.
- No PHPStan baseline file is introduced unless explicitly approved.

## Risk Assessment

- Medium risk around adding strict types to controller methods that may return mixed Laravel response types.
- Medium risk around Eloquent relation annotations if model relationships do not match database reality.

## Security Considerations

- Team policy and sharing code must keep authorization checks unchanged.
- Push subscription endpoints must preserve auth and ownership checks.
- Backup/encryption service changes must not alter secret handling.

## Next Steps

Proceed to final validation and commit split.

