<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\BackupSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Creates a scheduled server-side snapshot for one user. Dispatched by
 * `backup:snapshot-auto` for users whose snapshot_frequency is due; timeout
 * and tries mirror AutoBackupJob.
 */
class CreateSnapshotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int Seconds before the job is killed */
    public int $timeout = 300;

    /** @var int Attempts before giving up (one retry, with backoff) */
    public int $tries = 2;

    public function backoff(): int
    {
        return 120;
    }

    public function __construct(public User $user)
    {
    }

    public function handle(BackupSnapshotService $snapshots): void
    {
        $snapshot = $snapshots->createSnapshot($this->user, BackupSnapshotService::SOURCE_SCHEDULED);

        Log::info(sprintf('Scheduled snapshot #%s created for user ID #%s', $snapshot->id, $this->user->id));
    }
}
