<?php

namespace App\Services;

use App\Enums\PersonalAction;
use App\Models\PersonalActivityLog;
use App\Models\User;

class PersonalActivityLogger
{
    /**
     * Log a personal activity for a user.
     *
     * @param  User  $user  The user to log the activity for
     * @param  PersonalAction  $action  The action performed
     * @param  array  $metadata  Optional metadata about the action
     * @param  int|null  $targetAccountId  Optional target account ID
     */
    public function log(
        User $user,
        PersonalAction $action,
        array $metadata = [],
        ?int $targetAccountId = null
    ): void {
        // Fire-and-forget: don't let logging failures break the main operation
        try {
            PersonalActivityLog::create([
                'user_id'          => $user->id,
                'action'           => $action->value,
                'metadata'         => $metadata,
                'ip_address'       => request()->ip(),
                'user_agent'       => request()->userAgent(),
                'target_account_id' => $targetAccountId,
            ]);
        } catch (\Throwable) {
            // Silent failure — logging must not block the main operation
        }
    }
}
