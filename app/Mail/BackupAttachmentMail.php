<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers the .vault backup file as a mail attachment.
 * The payload is already double-encrypted by BackupService, so the
 * attachment is safe to transmit over email.
 */
class BackupAttachmentMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $payload,
        public string $filename,
    ) {
    }

    public function build(): static
    {
        return $this->subject('Your 2FA-Vault encrypted backup')
            ->view('mail.backup-attachment')
            ->with(['filename' => $this->filename])
            ->attachData($this->payload, $this->filename, [
                'mime' => 'application/octet-stream',
            ]);
    }
}
