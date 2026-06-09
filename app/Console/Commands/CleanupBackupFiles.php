<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupBackupFiles extends Command
{
    protected $signature = 'backup:cleanup {--hours=1 : Delete backups older than this many hours}';

    protected $description = 'Delete stale backup files from storage';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = now()->subHours($hours);
        $disk = Storage::disk('backups');
        $deleted = 0;

        foreach ($disk->files() as $file) {
            if ($disk->lastModified($file) < $cutoff->timestamp) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} stale backup file(s).");

        return self::SUCCESS;
    }
}
