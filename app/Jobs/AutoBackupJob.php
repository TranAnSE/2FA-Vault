<?php

namespace App\Jobs;

use App\Mail\AutoBackupNotificationMail;
use App\Models\User;
use App\Services\BackupDestinationService;
use App\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Generates a .vault backup for a user and pushes it to all of their
 * active backup destinations, then sends a summary notification.
 *
 * Per-destination failures are isolated (continue-on-error) so one bad
 * destination cannot prevent the others from receiving the backup.
 */
class AutoBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int Seconds before the job is killed */
    public int $timeout = 300;

    /** @var int Attempts before giving up */
    public int $tries = 1;

    public function __construct(public User $user)
    {
    }

    public function handle(BackupService $backup, BackupDestinationService $destinations): void
    {
        $envelope = $backup->generateEncryptedBackup($this->user);
        $payload = json_encode($envelope);
        $filename = 'backup-' . now()->utc()->format('Y-m-d-His') . '.vault';

        $errors = [];

        /** @var \App\Models\UserBackupDestination $destination */
        foreach ($this->user->backupDestinations()->where('is_active', true)->get() as $destination) {
            try {
                $destinations->send($destination, $payload, $filename);
                $destination->update([
                    'last_run_at'     => now(),
                    'last_run_status' => 'success',
                ]);
            } catch (\Throwable $e) {
                $destination->update([
                    'last_run_at'     => now(),
                    'last_run_status' => 'failed',
                ]);
                // Credentials must never appear in logs/mails — only the label
                $errors[] = $destination->label;
            }
        }

        // Record run timestamp in user preferences (raw JSON column path)
        $this->user['preferences->last_auto_backup_at'] = now()->utc()->toIso8601String();
        $this->user->save();

        Mail::to($this->user->email)->send(new AutoBackupNotificationMail($filename, $errors));
    }
}
