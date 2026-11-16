<?php

namespace App\Support\Passkeys;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Server-side persistence for the PRF enroll challenge (plan 261114-2101,
 * phase 2).
 *
 * The PRF endpoints live on the session-less api.v1 route group, so the
 * OptionsSession approach used by the web-route ceremonies is unavailable
 * there. The cache provides the same guarantees the M2 TTL gate needs:
 * server-issued, single-use, expiring challenge state — put() stores the
 * serialized options with the configured passkeys timeout, pull() returns
 * and removes them or throws the same ceremony-failure ValidationException.
 */
class PrfEnrollChallenge
{
    protected static function key(int|string $userId, string $credentialId) : string
    {
        // Dot-free key: some cache backends treat dots as nesting. Per-
        // credential scoping (review fix): parallel ceremonies for different
        // credentials of one user cannot clobber each other, and a submit
        // naming a different credential than the one challenged gets no
        // challenge at all.
        return 'prf_enroll_challenge_' . $userId . '_' . $credentialId;
    }

    /**
     * Store the serialized enrollment options with the TTL gate.
     */
    public static function put(int|string $userId, string $credentialId, string $serializedOptions) : void
    {
        Cache::put(self::key($userId, $credentialId), $serializedOptions, now()->addSeconds(PasskeysTimeout::seconds()));
    }

    /**
     * Pull the serialized options, rejecting the ceremony when they were
     * never stored or have expired (single use — the entry is consumed).
     *
     * @throws ValidationException when absent or expired
     */
    public static function pull(int|string $userId, string $credentialId) : string
    {
        $serialized = Cache::pull(self::key($userId, $credentialId));

        if (! $serialized) {
            throw ValidationException::withMessages([
                'credential' => __('Passkey session expired. Please try again.'),
            ]);
        }

        return $serialized;
    }
}
