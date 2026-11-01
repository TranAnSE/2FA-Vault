<?php

namespace Tests\Feature\Migrations;

use App\Models\Group;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Upgrade-path coverage for the many-to-many group migration: the legacy
 * scalar twofaccounts.group_id must transpose into per-user pivot rows, and
 * the pivot column types must stay INT so the FK still binds the legacy
 * groups/twofaccounts tables on MySQL (errno 3780 guard).
 */
class GroupAssignmentsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $transposeMigration;

    protected function setUp() : void
    {
        parent::setUp();

        $this->transposeMigration = require database_path('migrations/2026_11_01_000002_create_twofaccount_group_assignments_table.php');
    }

    /**
     * Simulate the legacy scalar state on the migrated (column-less) schema
     * and run the transpose directly.
     */
    private function seedLegacyScalarState() : array
    {
        Schema::table('twofaccounts', function ($table) {
            $table->unsignedInteger('group_id')->nullable();
        });

        $user         = User::factory()->create();
        $otherUser    = User::factory()->create();
        $group        = Group::factory()->for($user)->create();
        $inGroup      = TwoFAccount::factory()->create(['user_id' => $user->id]);
        $withoutGroup = TwoFAccount::factory()->create(['user_id' => $user->id]);
        $foreign      = TwoFAccount::factory()->create(['user_id' => $otherUser->id]);

        DB::table('twofaccounts')->where('id', $inGroup->id)->update(['group_id' => $group->id]);
        // an account owned by ANOTHER user carrying the same group id must
        // never transpose (the owner join filters it out)
        DB::table('twofaccounts')->where('id', $foreign->id)->update(['group_id' => $group->id]);

        return [$user, $group, $inGroup, $withoutGroup];
    }

    public function test_legacy_group_ids_transpose_into_pivot_rows() : void
    {
        [$user, $group, $inGroup] = $this->seedLegacyScalarState();

        $this->transposeMigration->backfillLegacyAssignments();

        $this->assertDatabaseHas('twofaccount_group_assignments', [
            'twofaccount_id' => $inGroup->id,
            'user_id'        => $user->id,
            'group_id'       => $group->id,
        ]);
        // foreign-owner row was skipped, ungrouped row produced nothing
        $this->assertDatabaseCount('twofaccount_group_assignments', 1);
    }

    public function test_transpose_is_idempotent() : void
    {
        [$user, $group, $inGroup] = $this->seedLegacyScalarState();

        $this->transposeMigration->backfillLegacyAssignments();
        $this->transposeMigration->backfillLegacyAssignments();

        $this->assertDatabaseCount('twofaccount_group_assignments', 1);
    }

    public function test_pivot_fk_columns_stay_int_on_mysql() : void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only introspection: sqlite cannot verify column width');
        }

        $columns = collect(DB::select('SHOW COLUMNS FROM twofaccount_group_assignments'))->keyBy('Field');

        foreach (['twofaccount_id', 'group_id'] as $field) {
            $this->assertSame(
                'int unsigned',
                $columns[$field]->Type,
                "pivot $field must stay INT unsigned — BIGINT breaks the FK against the legacy INT tables (errno 3780)"
            );
        }
        $this->assertSame('bigint unsigned', $columns['user_id']->Type);
    }

    /**
     * The SQLite rebuild must not fire the child tables' ON DELETE CASCADE:
     * dropping the legacy column used to wipe shared_accounts, tag
     * assignments, otp_logs and the freshly transposed pivot rows. Runs the
     * real up() on an isolated file-backed connection — PRAGMA foreign_keys
     * is a no-op inside RefreshDatabase's wrapping transaction, so the
     * migration must exercise its un-wrapped path there.
     */
    public function test_drop_group_id_preserves_child_rows_on_sqlite() : void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Exercises the SQLite table-rebuild path only');
        }

        $dbPath = storage_path('framework/testing/drop-group-id-' . uniqid() . '.sqlite');
        @mkdir(dirname($dbPath), 0777, true);
        touch($dbPath);
        config(['database.connections.sqlite_drop' => [
            'driver'                  => 'sqlite',
            'database'                => $dbPath,
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]]);
        $conn = DB::connection('sqlite_drop');

        // parent tables the FKs target
        $conn->statement('CREATE TABLE "users" ("id" integer primary key autoincrement not null)');
        $conn->statement('CREATE TABLE "groups" ("id" integer primary key autoincrement not null)');
        $conn->statement('CREATE TABLE "vaults" ("id" integer primary key autoincrement not null)');

        // the production twofaccounts shape at migration time (legacy
        // increments PK, FKs with the full action tails Laravel emits)
        $conn->statement('CREATE TABLE "twofaccounts" ("id" integer primary key autoincrement not null, "service" varchar not null, "group_id" integer, "user_id" integer, "vault_id" integer, foreign key("user_id") references users("id") on delete cascade on update no action, foreign key("group_id") references groups("id") on delete set null on update no action, foreign key("vault_id") references vaults("id") on delete set null on update no action)');
        $conn->statement('CREATE TABLE "twofaccount_group_assignments" ("id" integer primary key autoincrement not null, "twofaccount_id" integer not null, "user_id" integer not null, "group_id" integer not null, foreign key("twofaccount_id") references "twofaccounts"("id") on delete cascade, foreign key("group_id") references "groups"("id") on delete cascade)');
        $conn->statement('CREATE TABLE "shared_accounts" ("id" integer primary key autoincrement not null, "twofaccount_id" integer not null, foreign key("twofaccount_id") references "twofaccounts"("id") on delete cascade)');
        $conn->statement('CREATE TABLE "account_tag" ("twofaccount_id" integer not null, "tag_id" integer not null, primary key ("twofaccount_id", "tag_id"), foreign key("twofaccount_id") references "twofaccounts"("id") on delete cascade)');
        $conn->statement('CREATE TABLE "otp_logs" ("id" integer primary key autoincrement not null, "twofaccount_id" integer, foreign key("twofaccount_id") references "twofaccounts"("id") on delete cascade)');

        $conn->table('users')->insert(['id' => 1]);
        $conn->table('groups')->insert(['id' => 1]);
        $conn->table('twofaccounts')->insert(['id' => 1, 'service' => 'Drop', 'group_id' => 1, 'user_id' => 1]);
        $conn->table('twofaccount_group_assignments')->insert(['twofaccount_id' => 1, 'user_id' => 1, 'group_id' => 1]);
        $conn->table('shared_accounts')->insert(['twofaccount_id' => 1]);
        $conn->table('account_tag')->insert(['twofaccount_id' => 1, 'tag_id' => 7]);
        $conn->table('otp_logs')->insert(['twofaccount_id' => 1]);

        $this->beforeApplicationDestroyed(function () use ($dbPath) {
            @unlink($dbPath);
        });

        // the migration uses the default-connection facades; restore the
        // original default afterwards so RefreshDatabase's teardown rolls
        // back the right connection
        $originalDefault = config('database.default');
        config(['database.default' => 'sqlite_drop']);

        try {
            $dropMigration = require database_path('migrations/2026_11_01_000003_drop_group_id_from_twofaccounts_table.php');
            $dropMigration->up();
        } finally {
            config(['database.default' => $originalDefault]);
        }

        $this->assertFalse(Schema::hasColumn('twofaccounts', 'group_id'));
        // the account itself and every child row survive the rebuild, and no
        // child FK was rewritten onto the temp name
        $this->assertSame(1, $conn->table('twofaccounts')->count());
        $this->assertSame('Drop', $conn->table('twofaccounts')->value('service'));
        $this->assertSame(1, $conn->table('shared_accounts')->count());
        $this->assertSame(1, $conn->table('account_tag')->count());
        $this->assertSame(1, $conn->table('otp_logs')->count());
        $this->assertSame(1, $conn->table('twofaccount_group_assignments')->count());
        $this->assertStringNotContainsString('tmp_dropcol', $conn->select("SELECT sql FROM sqlite_master WHERE name = 'twofaccount_group_assignments'")[0]->sql);
        $this->assertSame([], $conn->select('PRAGMA foreign_key_check'));
    }
}
