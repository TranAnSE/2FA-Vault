<?php

namespace App\Support\Passkeys;

use RuntimeException;

/**
 * Resolves the configured passkeys timeout in seconds (the package config
 * expresses it in milliseconds).
 */
class PasskeysTimeout
{
    public static function seconds() : int
    {
        $seconds = intdiv((int) config('passkeys.timeout', 60000), 1000);

        if ($seconds < 1) {
            throw new RuntimeException('Passkey timeout must be a positive integer.');
        }

        return $seconds;
    }
}
