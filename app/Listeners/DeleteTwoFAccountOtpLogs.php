<?php

namespace App\Listeners;

use App\Events\TwoFAccountDeleted;
use App\Models\OtpLog;
use Illuminate\Support\Facades\Log;

/**
 * Removes all OTP generation log entries tied to a TwoFAccount when that
 * account is deleted, so the audit trail does not dangle.
 */
class DeleteTwoFAccountOtpLogs
{
    public function handle(TwoFAccountDeleted $event) : void
    {
        try {
            OtpLog::where('twofaccount_id', $event->twofaccount->id)->delete();
        } catch (\Throwable $e) { // @codeCoverageIgnore
            Log::warning(sprintf('Failed to purge OTP logs for TwoFAccount #%s: %s', $event->twofaccount->id, $e->getMessage()));
        }
    }
}
