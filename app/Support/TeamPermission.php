<?php

namespace App\Support;

/**
 * Closed catalog of team-matrix permissions (v1.4.0 RBAC).
 *
 * Every permission here maps to at least one real authorization check site,
 * and every team check site maps to a permission here or to the owner-locked
 * list — the matrix gates ADMINISTRATIVE capabilities only; secret
 * readability stays governed by the share mechanism (E2EE flow untouched).
 *
 * Owner-locked capabilities (`team.delete`, `roles.manage`, `activity.export`,
 * plus ownership transfer) are deliberately NOT part of the matrix: no role
 * combination can ever grant them — they stay hard-wired to `$team->owner_id`.
 */
final class TeamPermission
{
    public const MEMBERS_INVITE = 'members.invite';

    public const MEMBERS_REMOVE = 'members.remove';

    public const ROLES_ASSIGN = 'roles.assign';

    public const ACCOUNTS_SHARE = 'accounts.share';

    public const ACCOUNTS_UNSHARE_OTHERS = 'accounts.unshare_others';

    public const TEAM_EDIT = 'team.edit';

    public const ACTIVITY_VIEW = 'activity.view';

    /** @var list<string> */
    public const OWNER_LOCKED = ['team.delete', 'roles.manage', 'activity.export'];

    /** @return list<string> */
    public static function all() : array
    {
        return [
            self::MEMBERS_INVITE,
            self::MEMBERS_REMOVE,
            self::ROLES_ASSIGN,
            self::ACCOUNTS_SHARE,
            self::ACCOUNTS_UNSHARE_OTHERS,
            self::TEAM_EDIT,
            self::ACTIVITY_VIEW,
        ];
    }

    /**
     * The seeded system presets, reproducing v1.3.x effective behavior
     * exactly (verified against code, RT-8), including quirks:
     *
     * - `owner` is never consulted via the matrix (the owner short-circuit
     *   in Team::permissionsFor() keys on owner_id) — the preset exists only
     *   so the pivot row has a role definition.
     * - `admin` gets everything except roles.assign (owner-only today) —
     *   activity.export is owner-locked and not part of the matrix.
     * - `member` and `viewer` can share their own accounts (viewer being
     *   able to share is a v1.3.x behavior-compat quirk, kept exactly).
     *
     * @return array<string, list<string>>
     */
    public static function systemPresets() : array
    {
        $withoutRolesAssign = array_values(array_diff(self::all(), [self::ROLES_ASSIGN]));

        return [
            'owner'  => self::all(),
            'admin'  => $withoutRolesAssign,
            'member' => [self::ACCOUNTS_SHARE],
            'viewer' => [self::ACCOUNTS_SHARE],
        ];
    }
}
