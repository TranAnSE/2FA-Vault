<?php

namespace Tests\Feature\Models;

use App\Facades\IconStore;
use App\Models\AuthLog;
use App\Models\Group;
use App\Models\TwoFAccount;
use App\Models\User;
use App\Observers\UserObserver;
use Database\Factories\AuthLogFactory;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Data\OtpTestData;
use Tests\FeatureTestCase;
use Tests\Support\PasskeyAssertionFactory;

/**
 * UserModelTest test class
 */
#[CoversClass(User::class)]
#[CoversClass(UserObserver::class)]
class UserModelTest extends FeatureTestCase
{
    #[Test]
    public function test_admin_scope_returns_only_admin()
    {
        User::factory()->count(4)->create();

        $firstAdmin = User::factory()->administrator()->create([
            'name' => 'first',
        ]);
        $secondAdmin = User::factory()->administrator()->create([
            'name' => 'secondAdmin',
        ]);

        $admins = User::admins()->get();

        $this->assertCount(2, $admins);
        $this->assertEquals($admins[0]->is_admin, true);
        $this->assertEquals($admins[1]->is_admin, true);
        $this->assertEquals($admins[0]->name, $firstAdmin->name);
        $this->assertEquals($admins[1]->name, $secondAdmin->name);
    }

    #[Test]
    public function test_is_administrator_returns_correct_state()
    {
        $user  = User::factory()->create();
        $admin = User::factory()->administrator()->create();

        $this->assertEquals($user->isAdministrator(), false);
        $this->assertEquals($admin->isAdministrator(), true);
    }

    #[Test]
    public function test_promote_to_administrator_sets_administrator_status()
    {
        $user = User::factory()->create();

        $user->promoteToAdministrator();

        $this->assertEquals($user->isAdministrator(), true);
    }

    #[Test]
    public function test_promote_to_administrator_demote_administrator_status()
    {
        $admin = User::factory()->administrator()->create();
        // We need another admin to prevent demoting event returning false
        // and blocking the demotion
        $another_admin = User::factory()->administrator()->create();

        $admin->promoteToAdministrator(false);
        $admin->save();

        $this->assertFalse($admin->isAdministrator());
    }

    #[Test]
    public function test_reset_password_resets_password_with_success()
    {
        $user        = User::factory()->create();
        $oldPassword = $user->password;

        $user->resetPassword();

        $this->assertNotEquals($user->password, $oldPassword);
    }

    #[Test]
    public function test_reset_password_dispatch_event()
    {
        Event::fake();
        $user = User::factory()->create();

        Event::assertDispatched(
            PasswordReset::class,
            $user->resetPassword()
        );
    }

    #[Test]
    public function test_delete_removes_user_data()
    {
        Artisan::call('passport:keys', ['--no-interaction' => 1]);

        // Passport v13's passport:install uses interactive prompts that do not
        // work under --no-interaction; create the personal access client directly.
        app(\Laravel\Passport\ClientRepository::class)
            ->createPersonalAccessGrantClient(config('app.name'), 'users');

        $user = User::factory()->create();
        TwoFAccount::factory()->for($user)->create();
        AuthLog::factory()->for($user, 'authenticatable')->create();
        Group::factory()->for($user)->create();

        // Converted legacy-format credential (plan 261009): deletion must
        // cascade to the passkeys table.
        PasskeyAssertionFactory::convertedCredentialId($user);

        $user->createToken('myToken', []);
        Password::broker('webauthn')->createToken($user);
        Password::broker()->createToken($user);

        $user->delete();

        $this->assertDatabaseMissing('twofaccounts', [
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('groups', [
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('passkeys', [
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseMissing(config('auth.passwords.webauthn.table'), [
            'email' => $user->email,
        ]);
        $this->assertDatabaseMissing('oauth_access_tokens', [
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseMissing(config('auth.passwords.users.table'), [
            'email' => $user->email,
        ]);
        $this->assertDatabaseMissing('auth_logs', [
            'authenticatable_id' => $user->id,
        ]);
    }

    #[Test]
    public function test_delete_flushes_icons_of_user_twofaccounts()
    {
        Storage::fake('icons');

        $user = User::factory()->create();

        $twofaccount = TwoFAccount::factory()->for($user)->create([
            'icon' => OtpTestData::ICON_PNG,
        ]);
        IconStore::store(OtpTestData::ICON_PNG, base64_decode(OtpTestData::ICON_PNG_DATA));

        Storage::disk('icons')->assertExists($twofaccount->icon);

        $user->delete();

        Storage::disk('icons')->assertMissing($twofaccount->icon);
    }

    #[Test]
    public function test_delete_does_not_delete_the_only_admin()
    {
        $admin = User::factory()->administrator()->create();
        $this->assertEquals(1, User::admins()->count());

        $isDeleted = $admin->delete();

        $this->assertFalse($isDeleted);
    }

    #[Test]
    public function test_get_from_credential_id_retreives_the_user()
    {
        $user = User::factory()->create();

        $credentialId = PasskeyAssertionFactory::convertedCredentialId($user);

        $searched = User::getFromCredentialId($credentialId);

        $this->assertEquals($user->id, $searched->id);
    }

    #[Test]
    public function test_authentications_returns_user_auth_logs_sorted_by_latest_id()
    {
        $user = User::factory()->create();

        $tenDaysAgoAuthLog  = AuthLog::factory()->daysAgo(10)->for($user, 'authenticatable')->create();
        $fiveDaysAgoAuthLog = AuthLog::factory()->daysAgo(5)->for($user, 'authenticatable')->create();
        $lastAuthLog        = AuthLog::factory()->daysAgo(1)->for($user, 'authenticatable')->create();

        $authentications = $user->authentications()->get();

        $this->assertCount(3, $authentications);
        $this->assertEquals($lastAuthLog->id, $authentications[0]->id);
        $this->assertEquals($fiveDaysAgoAuthLog->id, $authentications[1]->id);
        $this->assertEquals($tenDaysAgoAuthLog->id, $authentications[2]->id);
    }

    #[Test]
    public function test_authentications_returns_user_auth_logs_only()
    {
        $user        = User::factory()->create();
        $anotherUser = User::factory()->create();

        $userAuthLog = AuthLog::factory()->daysAgo(10)->for($user, 'authenticatable')->create();
        AuthLog::factory()->daysAgo(5)->for($anotherUser, 'authenticatable')->create();

        $authentications = $user->authentications()->get();

        $this->assertCount(1, $authentications);
        $this->assertEquals($userAuthLog->id, $authentications[0]->id);
    }

    #[Test]
    public function test_authentications_by_period_returns_last_month_auth_logs()
    {
        $user = User::factory()->create();

        $twoMonthsAgoAuthLog    = AuthLog::factory()->duringLastThreeMonth()->for($user, 'authenticatable')->create();
        $duringLastMonthAuthLog = AuthLog::factory()->duringLastMonth()->for($user, 'authenticatable')->create();

        $authentications = $user->authenticationsByPeriod(1);

        $this->assertCount(1, $authentications);
        $this->assertEquals($duringLastMonthAuthLog->id, $authentications[0]->id);
    }

    #[Test]
    public function test_authentications_by_period_returns_last_three_months_auth_logs()
    {
        $user = User::factory()->create();

        $sixMonthsAgoAuthLog    = AuthLog::factory()->duringLastSixMonth()->for($user, 'authenticatable')->create();
        $threeMonthsAgoAuthLog  = AuthLog::factory()->duringLastThreeMonth()->for($user, 'authenticatable')->create();
        $duringLastMonthAuthLog = AuthLog::factory()->duringLastMonth()->for($user, 'authenticatable')->create();

        $authentications = $user->authenticationsByPeriod(3);

        $this->assertCount(2, $authentications);
        $this->assertEquals($duringLastMonthAuthLog->id, $authentications[0]->id);
        $this->assertEquals($threeMonthsAgoAuthLog->id, $authentications[1]->id);
    }

    #[Test]
    public function test_latest_authentication_returns_user_latest_auth_logs()
    {
        $user = User::factory()->create();

        $twoMonthsAgoAuthLog    = AuthLog::factory()->duringLastThreeMonth()->for($user, 'authenticatable')->create();
        $duringLastMonthAuthLog = AuthLog::factory()->duringLastMonth()->for($user, 'authenticatable')->create();

        $authentications = $user->latestAuthentication()->get();

        $this->assertCount(1, $authentications);
        $this->assertEquals($duringLastMonthAuthLog->id, $authentications[0]->id);
    }

    #[Test]
    public function test_latest_authentication_returns_user_latest_auth_logs_only()
    {
        $user        = User::factory()->create();
        $anotherUser = User::factory()->create();

        $userAuthLog        = AuthLog::factory()->duringLastThreeMonth()->for($user, 'authenticatable')->create();
        $anotherUserAuthLog = AuthLog::factory()->duringLastMonth()->for($anotherUser, 'authenticatable')->create();

        $authentications = $user->latestAuthentication()->get();

        $this->assertCount(1, $authentications);
        $this->assertEquals($userAuthLog->id, $authentications[0]->id);
    }

    #[Test]
    public function test_last_login_at_returns_user_last_auth_date()
    {
        $user = User::factory()->create();
        $now  = now();

        $tenDaysAgoAuthLog  = AuthLog::factory()->daysAgo(10)->for($user, 'authenticatable')->create();
        $fiveDaysAgoAuthLog = AuthLog::factory()->daysAgo(5)->for($user, 'authenticatable')->create();
        $lastAuthLog        = AuthLog::factory()->at($now)->for($user, 'authenticatable')->create();

        $lastLoginAt = $user->lastLoginAt();

        $this->assertEquals($lastLoginAt->startOfSecond(), $now->startOfSecond());
    }

    #[Test]
    public function test_last_login_at_returns_null_if_user_has_no_login()
    {
        $user = User::factory()->create();
        AuthLog::factory()->logoutOnly()->for($user, 'authenticatable')->create();

        $lastLoginAt = $user->lastLoginAt();

        $this->assertNull($lastLoginAt);
    }

    #[Test]
    public function test_last_successful_login_at_returns_user_last_successful_login_date()
    {
        $user = User::factory()->create();
        $now  = now();
        AuthLog::factory()->at($now)->for($user, 'authenticatable')->create();

        $lastSuccessfulLoginAt = $user->lastSuccessfulLoginAt();

        $this->assertEquals($lastSuccessfulLoginAt->startOfSecond(), $now->startOfSecond());
    }

    #[Test]
    public function test_last_successful_login_at_returns_null_if_user_has_no_successful_login()
    {
        $user = User::factory()->create();
        $now  = now();
        AuthLog::factory()->at($now)->failedLogin()->for($user, 'authenticatable')->create();

        $lastSuccessfulLoginAt = $user->lastSuccessfulLoginAt();

        $this->assertNull($lastSuccessfulLoginAt);
    }

    #[Test]
    public function test_last_login_ip_returns_user_last_login_ip()
    {
        $user = User::factory()->create();
        AuthLog::factory()->for($user, 'authenticatable')->create();

        $lastLoginIp = $user->lastLoginIp();

        $this->assertEquals(AuthLogFactory::IP, $lastLoginIp);
    }

    #[Test]
    public function test_last_login_ip_returns_null_if_user_has_no_auth_log()
    {
        $user = User::factory()->create();

        $lastLoginIp = $user->lastLoginIp();

        $this->assertNull($lastLoginIp);
    }

    #[Test]
    public function test_last_successful_login_ip_returns_user_last_successful_login_ip()
    {
        $user = User::factory()->create();
        AuthLog::factory()->for($user, 'authenticatable')->create();

        $lastSuccessfulLoginIp = $user->lastSuccessfulLoginIp();

        $this->assertEquals(AuthLogFactory::IP, $lastSuccessfulLoginIp);
    }

    #[Test]
    public function test_last_successful_login_ip_returns_null_if_user_has_no_successful_login()
    {
        $user = User::factory()->create();
        AuthLog::factory()->failedLogin()->for($user, 'authenticatable')->create();

        $lastSuccessfulLoginIp = $user->lastSuccessfulLoginIp();

        $this->assertNull($lastSuccessfulLoginIp);
    }

    #[Test]
    public function test_previous_login_at_returns_user_last_auth_date()
    {
        $user      = User::factory()->create();
        $now       = now();
        $yesterday = now()->subDay();

        $yesterdayAuthLog = AuthLog::factory()->at($yesterday)->for($user, 'authenticatable')->create();
        $lastAuthLog      = AuthLog::factory()->at($now)->for($user, 'authenticatable')->create();

        $previousLoginAt = $user->previousLoginAt();

        $this->assertEquals($previousLoginAt->startOfSecond(), $yesterday->startOfSecond());
    }

    #[Test]
    public function test_previous_login_at_returns_null_if_user_has_no_auth_log()
    {
        $user = User::factory()->create();

        $previousLoginAt = $user->previousLoginAt();

        $this->assertNull($previousLoginAt);
    }

    #[Test]
    public function test_previous_login_ip_returns_user_last_auth_ip()
    {
        $user      = User::factory()->create();
        $yesterday = now()->subDay();

        AuthLog::factory()->for($user, 'authenticatable')->create();
        AuthLog::factory()->at($yesterday)->for($user, 'authenticatable')->create();

        $previousLoginIp = $user->previousLoginIp();

        $this->assertEquals(AuthLogFactory::IP, $previousLoginIp);
    }

    #[Test]
    public function test_previous_login_ip_returns_null_if_user_has_no_auth_log()
    {
        $user = User::factory()->create();

        $previousLoginIp = $user->previousLoginIp();

        $this->assertNull($previousLoginIp);
    }
}
