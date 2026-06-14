<?php

namespace Tests\Unit\Migrators;

use App\Exceptions\InvalidMigrationDataException;
use App\Models\TwoFAccount;
use App\Services\Migrators\RaivoMigrator;
use App\Services\SettingService;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RaivoMigratorTest test class
 */
#[CoversClass(RaivoMigrator::class)]
class RaivoMigratorTest extends TestCase
{
    protected function setUp() : void
    {
        parent::setUp();

        // Raivo migrator reads Settings::get('useEncryption'); keep it off (default) so secrets stay plaintext.
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
    public function test_migrate_maps_raivo_entries_to_twofaccounts()
    {
        $payload = file_get_contents(base_path('tests/fixtures/raivo-export.json'));

        $accounts = (new RaivoMigrator)->migrate($payload);

        $this->assertCount(2, $accounts);
        $this->assertContainsOnlyInstancesOf(TwoFAccount::class, $accounts);

        $first = $accounts->first();
        $this->assertSame('SyntheticService', $first->service);
        $this->assertSame('alice@synthetic.example', $first->account);
        $this->assertSame('JBSWY3DPEHPK3PXP', $first->secret);
        $this->assertSame('totp', $first->otp_type);
        $this->assertSame(6, (int) $first->digits);
        $this->assertSame(30, (int) $first->period);
        $this->assertSame('sha1', $first->algorithm);

        $second = $accounts->last();
        $this->assertSame('AnotherService', $second->service);
        $this->assertSame('sha256', $second->algorithm);
        $this->assertSame(8, (int) $second->digits);
        $this->assertSame(60, (int) $second->period);
    }

    #[Test]
    public function test_migrate_throws_invalid_data_exception_when_key_missing()
    {
        $this->expectException(InvalidMigrationDataException::class);

        (new RaivoMigrator)->migrate('{"notRaivo": true}');
    }

    #[Test]
    public function test_migrate_throws_invalid_data_exception_on_invalid_json()
    {
        $this->expectException(InvalidMigrationDataException::class);

        (new RaivoMigrator)->migrate('this-is-not-json');
    }
}
