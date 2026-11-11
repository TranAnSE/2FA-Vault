<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use ParagonIE\ConstantTime\Base64UrlSafe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;
use Tests\Support\PasskeyAssertionFactory;

/**
 * ETL command tests (plan 261009-passkeys-migration phase 4): fixtures are
 * REAL legacy-format rows (encrypted PEM behind the laragear cast) converted
 * through the production path.
 */
#[CoversClass(\App\Console\Commands\MigrateWebauthnCredentials::class)]
class MigrateWebauthnCredentialsTest extends FeatureTestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function dry_run_reports_without_writing() : void
    {
        $user = User::factory()->create();

        PasskeyAssertionFactory::legacyOnly($user);
        PasskeyAssertionFactory::legacyOnly($user);

        // One already-converted row: must be reported as SKIPPED.
        PasskeyAssertionFactory::convertedCredentialId($user);

        $exit = Artisan::call('2fauth:migrate-webauthn-credentials', ['--dry-run' => true]);

        $this->assertSame(0, $exit);

        $output = Artisan::output();
        $this->assertStringContainsString('Converted (dry-run)', $output);
        $this->assertStringContainsString('Skipped (already converted)', $output);

        $this->assertDatabaseCount('passkeys', 1);
    }

    #[Test]
    public function live_run_converts_then_re_run_is_idempotent() : void
    {
        $user = User::factory()->create();

        $first = PasskeyAssertionFactory::legacyOnly($user);
        $second = PasskeyAssertionFactory::legacyOnly($user);

        $exit = Artisan::call('2fauth:migrate-webauthn-credentials', ['--force' => true]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseCount('passkeys', 2);

        $this->assertDatabaseHas('passkeys', ['credential_id' => $first['credentialId']]);
        $this->assertDatabaseHas('passkeys', ['credential_id' => $second['credentialId']]);

        // C6: the post-cutover re-run is REQUIRED; --force skips converted
        // rows and reports them.
        $exit = Artisan::call('2fauth:migrate-webauthn-credentials', ['--force' => true]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseCount('passkeys', 2);
        $this->assertStringContainsString('Skipped', Artisan::output());
    }

    #[Test]
    public function verify_passes_on_converted_rows() : void
    {
        $user = User::factory()->create();

        PasskeyAssertionFactory::legacyOnly($user);
        PasskeyAssertionFactory::legacyOnly($user);

        Artisan::call('2fauth:migrate-webauthn-credentials', ['--force' => true, '--verify' => true]);

        $output = Artisan::output();

        $this->assertStringContainsString('Verified 2 converted row(s).', $output);
    }

    #[Test]
    public function failing_row_reports_and_exits_nonzero() : void
    {
        $user = User::factory()->create();

        $good = PasskeyAssertionFactory::legacyOnly($user);
        // R3 fixture: decrypts fine (passes the pre-flight PEM check) but
        // has a malformed user_id uuid, so the CONVERSION fails and the
        // row must abort alone.
        $fakePem = PasskeyAssertionFactory::pemFixture();
        DB::table('webauthn_credentials')->insert([
            'id' => Base64UrlSafe::encodeUnpadded(random_bytes(32)),
            'authenticatable_type' => User::class,
            'authenticatable_id' => $user->id,
            'user_id' => 'not-a-uuid',
            'counter' => 0,
            'rp_id' => 'localhost',
            'origin' => config('app.url'),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'attestation_format' => 'none',
            'public_key' => Crypt::encryptString($fakePem),
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        $exit = Artisan::call('2fauth:migrate-webauthn-credentials', ['--force' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FAIL', Artisan::output());

        // The good row converted regardless.
        $this->assertDatabaseHas('passkeys', ['credential_id' => $good['credentialId']]);
    }

    #[Test]
    public function refuses_without_force_when_target_has_rows() : void
    {
        $user = User::factory()->create();

        PasskeyAssertionFactory::convertedCredentialId($user);

        $exit = Artisan::call('2fauth:migrate-webauthn-credentials');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--force', Artisan::output());
    }

    #[Test]
    public function empty_tables_noop() : void
    {
        $exit = Artisan::call('2fauth:migrate-webauthn-credentials');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Nothing to migrate.', Artisan::output());
    }
}
