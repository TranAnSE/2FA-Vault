<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * Tests the Passport 13 oauth_clients schema migration (upstream #563):
 * legacy client rows are backfilled to the new schema so existing tokens
 * (incl. PATs) keep authenticating after the migration.
 */
class Passport13SchemaMigrationTest extends FeatureTestCase
{
    /** @var string */
    private const MIGRATION_PATH = 'database/migrations/2026_08_29_175933_update_oauth_clients_table_for_passport_13.php';

    #[Test]
    public function test_fresh_schema_has_passport_13_columns()
    {
        $this->assertTrue(Schema::hasColumn('oauth_clients', 'owner_id'));
        $this->assertTrue(Schema::hasColumn('oauth_clients', 'owner_type'));
        $this->assertTrue(Schema::hasColumn('oauth_clients', 'redirect_uris'));
        $this->assertTrue(Schema::hasColumn('oauth_clients', 'grant_types'));

        $this->assertFalse(Schema::hasColumn('oauth_clients', 'user_id'));
        $this->assertFalse(Schema::hasColumn('oauth_clients', 'redirect'));
        $this->assertFalse(Schema::hasColumn('oauth_clients', 'personal_access_client'));
        $this->assertFalse(Schema::hasColumn('oauth_clients', 'password_client'));
        $this->assertFalse(Schema::hasTable('oauth_personal_access_clients'));
    }

    #[Test]
    public function test_legacy_client_rows_are_backfilled()
    {
        $user = User::factory()->create();

        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION_PATH, '--force' => true]);

        // Legacy schema restored: insert a row shaped like a pre-migration PAC client.
        DB::table('oauth_clients')->insert([
            'id'                     => 4242,
            'user_id'                => null,
            'name'                   => 'Legacy client',
            'secret'                 => 'legacysecret',
            'provider'               => 'users',
            'redirect'               => 'http://localhost/callback',
            'personal_access_client' => true,
            'password_client'        => false,
            'revoked'                => false,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        Artisan::call('migrate', ['--path' => self::MIGRATION_PATH, '--force' => true]);

        $client = DB::table('oauth_clients')->where('id', 4242)->first();

        $this->assertNotNull($client);
        $this->assertSame(['personal_access', 'refresh_token'], json_decode($client->grant_types, true));
        $this->assertSame(['http://localhost/callback'], json_decode($client->redirect_uris, true));
        $this->assertFalse(Schema::hasTable('oauth_personal_access_clients'));
    }

    #[Test]
    public function test_legacy_owned_client_rows_get_owner_morph()
    {
        $user = User::factory()->create();

        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION_PATH, '--force' => true]);

        DB::table('oauth_clients')->insert([
            'id'                     => 4243,
            'user_id'                => $user->id,
            'name'                   => 'Legacy owned client',
            'secret'                 => null,
            'provider'               => 'users',
            'redirect'               => 'http://localhost/callback',
            'personal_access_client' => false,
            'password_client'        => false,
            'revoked'                => false,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        Artisan::call('migrate', ['--path' => self::MIGRATION_PATH, '--force' => true]);

        $client = DB::table('oauth_clients')->where('id', 4243)->first();

        $this->assertNotNull($client);
        $this->assertSame($user->id, $client->owner_id);
        $this->assertSame(User::class, $client->owner_type);
    }

    #[Test]
    public function test_round_trip_preserves_passport_13_created_client()
    {
        $client = app(\Laravel\Passport\ClientRepository::class)
            ->createPersonalAccessGrantClient(config('app.name'), 'users');

        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION_PATH, '--force' => true]);
        Artisan::call('migrate', ['--path' => self::MIGRATION_PATH, '--force' => true]);

        $row = DB::table('oauth_clients')->where('id', $client->getKey())->first();

        $this->assertNotNull($row);
        $this->assertContains('personal_access', json_decode($row->grant_types, true));
        $this->assertSame(['personal_access', 'refresh_token'], json_decode($row->grant_types, true));
    }
}
