<?php

namespace Tests\Unit\Migrators;

use App\Exceptions\InvalidMigrationDataException;
use App\Models\TwoFAccount;
use App\Services\Migrators\AuthyMigrator;
use App\Services\SettingService;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AuthyMigratorTest test class
 *
 * Authy support is BETA — only authy-export JSON is accepted.
 * Native encrypted Authy backups are NOT supported.
 */
#[Group('beta')]
#[CoversClass(AuthyMigrator::class)]
class AuthyMigratorTest extends TestCase
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
    public function test_migrate_maps_authy_export_accounts_to_twofaccounts()
    {
        $payload = file_get_contents(base_path('tests/fixtures/authy-export.json'));

        $accounts = (new AuthyMigrator)->migrate($payload);

        $this->assertCount(2, $accounts);
        $this->assertContainsOnlyInstancesOf(TwoFAccount::class, $accounts);

        // First account has "Service:account" name format → split
        $first = $accounts->first();
        $this->assertSame('SyntheticService', $first->service);
        $this->assertSame('alice@synthetic.example', $first->account);
        $this->assertSame('JBSWY3DPEHPK3PXP', $first->secret);
        $this->assertSame('totp', $first->otp_type);
        $this->assertSame(6, (int) $first->digits);
        $this->assertSame('sha1', $first->algorithm);
        $this->assertSame(30, (int) $first->period);

        // Second account has a bare name → used as both service and account
        $second = $accounts->last();
        $this->assertSame('AnotherService', $second->service);
        $this->assertSame('AnotherService', $second->account);
        $this->assertSame(7, (int) $second->digits);
    }

    #[Test]
    public function test_migrate_throws_invalid_data_exception_when_accounts_missing()
    {
        $this->expectException(InvalidMigrationDataException::class);

        (new AuthyMigrator)->migrate('{"notAuthy": true}');
    }

    #[Test]
    public function test_migrate_refuses_native_encrypted_backup()
    {
        // Native Authy backups are encrypted binary blobs, not JSON.
        // The migrator must refuse them explicitly rather than returning an empty list.
        $this->expectException(InvalidMigrationDataException::class);

        (new AuthyMigrator)->migrate('encrypted-binary-blob-not-json');
    }
}
