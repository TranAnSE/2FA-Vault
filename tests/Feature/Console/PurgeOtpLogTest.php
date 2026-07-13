<?php

namespace Tests\Feature\Console;

use App\Models\OtpLog;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\FeatureTestCase;

class PurgeOtpLogTest extends FeatureTestCase
{
    use RefreshDatabase;

    public function test_purge_otp_log_command_deletes_old_entries() : void
    {
        $user    = User::factory()->create();
        $account = TwoFAccount::factory()->for($user)->create();

        // A recent log (within retention).
        OtpLog::create([
            'requester_id'   => $user->id,
            'owner_id'       => $user->id,
            'twofaccount_id' => $account->id,
            'otp_type'       => 'totp',
            'generated_at'   => now(),
        ]);

        // An old log (beyond default 365-day retention).
        OtpLog::create([
            'requester_id'   => $user->id,
            'owner_id'       => $user->id,
            'twofaccount_id' => $account->id,
            'otp_type'       => 'totp',
            'generated_at'   => Carbon::now()->subDays(400),
        ]);

        $this->artisan('2fauth:purge-otp-log')
            ->assertSuccessful();

        // Only the recent log remains.
        $this->assertDatabaseCount('otp_logs', 1);
    }

    public function test_purge_otp_log_honors_configured_retention() : void
    {
        config(['2fauth.config.otpLogRetentionTime' => 10]);

        $user    = User::factory()->create();
        $account = TwoFAccount::factory()->for($user)->create();

        OtpLog::create([
            'requester_id'   => $user->id,
            'owner_id'       => $user->id,
            'twofaccount_id' => $account->id,
            'otp_type'       => 'totp',
            'generated_at'   => Carbon::now()->subDays(5), // within 10 days
        ]);
        OtpLog::create([
            'requester_id'   => $user->id,
            'owner_id'       => $user->id,
            'twofaccount_id' => $account->id,
            'otp_type'       => 'totp',
            'generated_at'   => Carbon::now()->subDays(15), // beyond 10 days
        ]);

        $this->artisan('2fauth:purge-otp-log')->assertSuccessful();

        $this->assertDatabaseCount('otp_logs', 1);
    }
}
