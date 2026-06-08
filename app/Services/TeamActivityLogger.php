<?php

namespace App\Services;

use App\Enums\TeamAction;
use App\Models\Team;
use App\Models\TeamActivityLog;
use App\Models\TwoFAccount;
use App\Models\User;

class TeamActivityLogger
{
    public function log(
        Team $team,
        User $actor,
        TeamAction $action,
        ?array $metadata = null,
        ?User $targetUser = null,
        ?TwoFAccount $targetAccount = null,
    ): void {
        // Fire-and-forget: don't let logging failures break the main operation
        try {
            TeamActivityLog::create([
                'team_id'           => $team->id,
                'user_id'           => $actor->id,
                'action'            => $action->value,
                'metadata'          => $metadata,
                'target_user_id'    => $targetUser?->id,
                'target_account_id' => $targetAccount?->id,
            ]);
        } catch (\Throwable) {
            // Silent failure — logging must not block the main operation
        }
    }
}
