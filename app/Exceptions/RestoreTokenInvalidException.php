<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The restore confirmation token is missing, expired, or does not match the
 * one issued by the dry-run. Mapped to 422 `restore_token_invalid` by the
 * controller.
 */
class RestoreTokenInvalidException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No valid confirmation token for this restore — run a fresh dry-run first.');
    }
}
