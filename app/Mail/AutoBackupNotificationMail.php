<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AutoBackupNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $filename  Name of the generated backup file
     * @param  array<int,string>  $failedDestinations  Labels of destinations that failed
     */
    public function __construct(
        public string $filename,
        public array $failedDestinations = [],
    ) {
    }

    public function build(): static
    {
        $success = empty($this->failedDestinations);

        return $this->subject($success ? 'Your 2FA-Vault backup completed' : 'Your 2FA-Vault backup completed with errors')
            ->view('mail.auto-backup-notification')
            ->with([
                'filename'    => $this->filename,
                'succeeded'   => $success,
                'failed'      => $this->failedDestinations,
                'generatedAt' => now()->utc()->toIso8601String(),
            ]);
    }
}
