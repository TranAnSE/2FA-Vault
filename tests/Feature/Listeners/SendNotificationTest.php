<?php

namespace Tests\Feature\Listeners;

use App\Listeners\Authentication\FailedLoginListener;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * SendNotificationTest test class
 *
 * Asserts that notifications fail silently when the mailer is misconfigured,
 * mirroring upstream 6e1e6b65 tests adapted to fork send sites.
 */
#[CoversClass(FailedLoginListener::class)]
#[CoversClass(User::class)]
class SendNotificationTest extends FeatureTestCase
{
    private function breakMailer() : void
    {
        config(['mail.default' => 'smtp']);
        config(['mail.mailers.smtp.port' => 'invalidPortNumberToTriggerSmtpTransportError']);
    }

    #[Test]
    public function test_failed_login_notification_fails_silently()
    {
        $this->breakMailer();

        $user                                     = User::factory()->create();
        $user['preferences->notifyOnFailedLogin'] = true;
        $user->save();

        $event    = new Failed('web', $user, []);
        $listener = new FailedLoginListener(Request::create('/'));

        $listener->handle($event);

        $this->assertTrue(true, 'No exception should propagate when the mailer fails');
    }

    #[Test]
    public function test_password_reset_notification_fails_silently()
    {
        $this->breakMailer();

        $user = User::factory()->create();

        $user->sendPasswordResetNotification('token');

        $this->assertTrue(true, 'No exception should propagate when the mailer fails');
    }

    #[Test]
    public function test_webauthn_recovery_notification_fails_silently()
    {
        $this->breakMailer();

        $user = User::factory()->create();

        $user->sendWebauthnRecoveryNotification('token');

        $this->assertTrue(true, 'No exception should propagate when the mailer fails');
    }
}
