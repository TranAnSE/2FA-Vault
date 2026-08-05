<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @return void
     *
     * @codeCoverageIgnore Because no code will always remains Not Executed code
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('cache:prune-stale-tags')->hourly();
        $schedule->command('emergency:process')->dailyAt('03:00');
        // Auto-backup: dispatches jobs for users whose scheduled backup is due
        $schedule->command('backup:auto')->everyMinute()->withoutOverlapping();
        // Backup rotation: prune stale encrypted .vault files older than the
        // configured retention (default 1 hour) so exports don't accumulate on
        // disk indefinitely. The underlying cleanup command is tested in
        // tests/Feature/Console/CleanupBackupFilesTest.php.
        $schedule->command('backup:cleanup --hours=' . (int) config('2fauth.config.backupRetentionHours', 1))->hourly();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
