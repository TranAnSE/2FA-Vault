<?php

namespace App\Console\Commands;

use App\Services\BackupSnapshotService;
use Illuminate\Console\Command;

/**
 * Reconciles the snapshot store: removes encrypted files with no row
 * (rollback leftovers — CleanupBackupFiles only sweeps the `backups` disk,
 * never `snapshots`) and dead rows whose file vanished. Run after migrations
 * roll back, and scheduled daily.
 */
class PruneSnapshots extends Command
{
    protected $signature = 'snapshots:prune';

    protected $description = 'Remove orphaned snapshot files and dead snapshot rows';

    public function handle(BackupSnapshotService $snapshots): int
    {
        $result = $snapshots->pruneOrphans();

        $this->info(sprintf(
            'Snapshot prune done: %d orphan file(s) removed, %d dead row(s) removed.',
            $result['files_removed'],
            $result['rows_removed'],
        ));

        return self::SUCCESS;
    }
}
