<?php

namespace App\Console\Commands;

use App\Jobs\CreateSnapshotJob;
use App\Models\BackupSnapshot;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dispatches CreateSnapshotJob for every user whose snapshot schedule
 * (preference snapshot_frequency: daily|weekly, at snapshot_time UTC) is due.
 * Scheduled to run every minute with catch-up semantics from Console\Kernel,
 * mirroring backup:auto.
 */
class RunScheduledSnapshots extends Command
{
    protected $signature = 'backup:snapshot-auto';

    protected $description = 'Dispatch scheduled snapshot jobs for users whose snapshot is due';

    /**
     * @codeCoverageIgnore Scheduler-driven; logic delegated to isSnapshotDue which is unit-tested.
     */
    public function handle() : int
    {
        $candidateIds = DB::table('users')
            ->whereIn('preferences->snapshot_frequency', ['daily', 'weekly'])
            ->pluck('id');

        $now = Carbon::now('UTC');

        // One query per run, not per candidate: hydrate the due set in a
        // single keyed fetch.
        $candidates = User::whereIn('id', $candidateIds)->get()->keyBy('id');

        foreach ($candidateIds as $userId) {
            $user = $candidates->get($userId);
            if (! $user) {
                continue;
            }

            // Last run reference = the newest SCHEDULED snapshot row (DB truth,
            // not a preference that could drift).
            $lastRun = BackupSnapshot::where('user_id', $user->id)
                ->where('source', 'scheduled')
                ->orderByDesc('created_at')
                ->first()
                ?->created_at;

            if (! $this->isSnapshotDue($user->preferences ?? [], $now, $lastRun)) {
                continue;
            }

            CreateSnapshotJob::dispatch($user);
        }

        return self::SUCCESS;
    }

    /**
     * Determine whether a scheduled snapshot is due for the user at $now.
     * C12 catch-up semantics (mirrors RunAutoBackupsCommand::isBackupDue): a
     * missed scheduler tick does not skip the window.
     *
     * @param  array<string,mixed>  $preferences
     */
    public static function isSnapshotDue(array $preferences, Carbon $now, ?Carbon $lastRun) : bool
    {
        $frequency = $preferences['snapshot_frequency'] ?? 'off';
        if (! in_array($frequency, ['daily', 'weekly'], true)) {
            return false;
        }

        $time            = $preferences['snapshot_time'] ?? '02:00';
        [$hour, $minute] = array_pad(explode(':', (string) $time), 2, '0');

        $dueAt = $now->copy()->utc()->startOfDay()->setTime((int) $hour, (int) $minute);

        // Not yet due today
        if ($now->utc()->lessThan($dueAt)) {
            return false;
        }

        if ($lastRun === null) {
            return true;
        }

        return match ($frequency) {
            'daily'  => $lastRun->utc()->lessThan($dueAt),
            'weekly' => $lastRun->utc()->lessThan($dueAt->copy()->subWeek()),
            default  => false,
        };
    }
}
