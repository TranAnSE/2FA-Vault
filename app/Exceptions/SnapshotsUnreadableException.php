<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A backup snapshot cannot be read. Carries a stable reason surfaced to
 * clients as `unreadable_reason` (e.g. `app_key_rotated` after an APP_KEY
 * rotation, RT-13) — never a raw DecryptException 500.
 */
class SnapshotsUnreadableException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Snapshot unreadable: {$reason}");
    }
}
