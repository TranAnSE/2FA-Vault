<?php

namespace App\Mail;

use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @var UserInvitation
     */
    protected UserInvitation $invitation;

    /**
     * Create a new message instance.
     *
     * @param  UserInvitation  $invitation
     */
    public function __construct(UserInvitation $invitation)
    {
        $this->invitation = $invitation;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $invitationUrl = config('app.url') . '/register?invitation=' . $this->invitation->token;

        return $this->subject('Invitation to join 2FA-Vault')
            ->view('emails.user-invitation')
            ->with([
                'email' => $this->invitation->email,
                'invitationUrl' => $invitationUrl,
                'expiresAt' => $this->invitation->expires_at->format('Y-m-d'),
            ]);
    }
}
