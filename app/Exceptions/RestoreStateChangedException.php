<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The vault changed since the dry-run (diffHash mismatch under the restore
 * row lock). Mapped to 409 `restore_state_changed` — the client re-runs the
 * dry-run. The token is consumed either way (single-use).
 */
class RestoreStateChangedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The vault changed since the diff was shown. Review the fresh diff and confirm again.');
    }
}
