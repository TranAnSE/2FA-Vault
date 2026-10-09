<?php

namespace App\Support;

/**
 * Immutable set of permission slugs resolved by Team::permissionsFor().
 */
final class TeamPermissionSet
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(private readonly array $permissions) {}

    public function has(string $permission) : bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** Whether every permission of $other is contained in this set (subset constraint, RT-15). */
    public function covers(self $other) : bool
    {
        return count(array_diff($other->permissions, $this->permissions)) === 0;
    }

    /** @return list<string> */
    public function toArray() : array
    {
        return $this->permissions;
    }
}
