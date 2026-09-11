<?php

namespace App\Listeners;

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;

class LogNotificationListener
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(mixed $event)
    {
        // $event->channel
        // $event->notifiable
        // $event->notification
        // $event->response

        if ($event instanceof NotificationSent)
        {
            Log::info(sprintf('Notification of type %s sent via channel %s to user ID #%s', get_class($event->notification), $event->channel, $event->notifiable->id));
        }
        else if ($event instanceof NotificationFailed)
        {
            Log::warning(sprintf('Notification sending to user ID #%s via channel %s failed (%s)', $event->notifiable->id, $event->channel, get_class($event->notification)));
            Log::warning('Review your MAIL_* environment variables, especially if you are using SMTP, and test sending emails from the admin panel of the 2FAuth web app.');
        }
    }
}
