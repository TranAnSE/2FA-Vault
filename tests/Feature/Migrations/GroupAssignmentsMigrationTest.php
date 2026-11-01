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
}
