<?php

namespace Tests\Unit\Migrators;

use App\Exceptions\InvalidMigrationDataException;
use App\Models\TwoFAccount;
use App\Services\Migrators\AndOtpMigrator;
use App\Services\SettingService;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AndOtpMigratorTest test class
 */
#[CoversClass(AndOtpMigrator::class)]
class AndOtpMigratorTest extends TestCase
{
    protected function setUp() : void
    {
        parent::setUp();

        $this->mock(SettingService::class, function (MockInterface $settings) {
            foreach (config('2fauth.settings') as $setting => $value) {
                $settings->shouldReceive('get')
                    ->with($setting)
                    ->andReturn($value);
            }
        });
    }

    protected function tearDown() : void
    {
        $this->forgetMock(SettingService::class);

        parent::tearDown();
    }

    #[Test]
    public function test_migrate_maps_andotp_tokens_to_twofaccounts()
    {
        $payload = file_get_contents(base_path('tests/fixtures/andotp-export.json'));

        $accounts = (new AndOtpMigrator)->migrate($payload);

        $this->assertCount(2, $accounts);
        $this->assertContainsOnlyInstancesOf(TwoFAccount::class, $accounts);

        $totp = $accounts->first();
        $this->assertSame('SyntheticService', $totp->service);
        $this->assertSame('alice@synthetic.example', $totp->account);
        $this->assertSame('JBSWY3DPEHPK3PXP', $totp->secret);
        $this->assertSame('totp', $totp->otp_type);
        $this->assertSame(6, (int) $totp->digits);
        $this->assertSame(30, (int) $totp->period);
        $this->assertSame('sha1', $totp->algorithm);

        $hotp = $accounts->last();
        $this->assertSame('hotp', $hotp->otp_type);
        $this->assertSame('sha256', $hotp->algorithm);
        $this->assertSame(5, (int) $hotp->counter);
    }

    #[Test]
    public function test_migrate_throws_invalid_data_exception_when_tokens_missing()
    {
        $this->expectException(InvalidMigrationDataException::class);

        (new AndOtpMigrator)->migrate('{"encrypted": false}');
    }

    #[Test]
    public function test_migrate_throws_for_encrypted_export()
    {
        $this->expectException(InvalidMigrationDataException::class);

        (new AndOtpMigrator)->migrate('{"encrypted": true, "tokens": []}');
    }
}
