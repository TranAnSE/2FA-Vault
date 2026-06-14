<?php

namespace App\Console\Commands;

use App\Jobs\AutoBackupJob;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dispatches AutoBackupJob for every user whose scheduled auto-backup is due.
 * Scheduled to run every minute from Console\Kernel.
 */
class RunAutoBackupsCommand extends Command
{
    protected $signature = 'backup:auto';

    protected $description = 'Dispatch auto-backup jobs for users whose backup is due';

    /**
     * @codeCoverageIgnore Scheduler-driven; logic delegated to isBackupDue which is unit-tested.
     */
    public function handle(): int
    {
        $candidateIds = DB::table('users')
            ->whereJsonContains('preferences->auto_backup_enabled', true)
            ->pluck('id');

        $now = Carbon::now('UTC');

        foreach ($candidateIds as $userId) {
            $user = User::find($userId);
            if (!$user || !$this->isUserBackupDue($user, $now)) {
                continue;
            }

            AutoBackupJob::dispatch($user);
        }

        return self::SUCCESS;
    }

    /**
     * Determine whether a backup is due for the user at $now.
     * Public/static so it can be unit-tested in isolation.
     *
     * @param  array<string,mixed>  $preferences
     */
    public static function isBackupDue(array $preferences, Carbon $now, ?Carbon $lastRun): bool
    {
        $frequency = $preferences['auto_backup_frequency'] ?? 'daily';
        $time = $preferences['auto_backup_time'] ?? '02:00';

        [$hour, $minute] = array_pad(explode(':', (string) $time), 2, '0');

        // Must match the configured time-of-day (UTC) to the minute
        if ((int) $now->format('H') !== (int) $hour || (int) $now->format('i') !== (int) $minute) {
            return false;
        }

        if ($lastRun === null) {
            return true;
        }

        return match ($frequency) {
            'daily'   => $lastRun->diffInDays($now) >= 1,
            'weekly'  => $lastRun->diffInWeeks($now) >= 1,
            'monthly' => $lastRun->diffInMonths($now) >= 1,
            default   => false,
        };
    }

    /**
     * Wrapper resolving the user's preferences + last run, then delegating.
     */
    private function isUserBackupDue(User $user, Carbon $now): bool
    {
        $preferences = collect($user->preferences)->toArray();
        $last = $preferences['last_auto_backup_at'] ?? null;

        return self::isBackupDue(
            $preferences,
            $now,
            $last ? Carbon::parse($last)->utc() : null
        );
    }
}
