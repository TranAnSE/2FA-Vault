<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up() : void
    {
        if (! Schema::hasColumn('twofaccounts', 'group_id')) {
            return;
        }

        // SQLite cannot drop a column carrying an FK constraint via ALTER —
        // rebuild the table from its own CREATE statement instead. No
        // driver-branching no-ops: every supported engine loses the column
        // for real.
        if (DB::getDriverName() === 'sqlite') {
            $this->dropColumnSqlite('twofaccounts', 'group_id');

            return;
        }

        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
        });

        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->dropColumn('group_id');
        });
    }

    /**
     * Reverse the migrations: recreate the column and backfill it
     * best-effort with each account's first group assignment.
     */
    public function down() : void
    {
        if (Schema::hasColumn('twofaccounts', 'group_id')) {
            return;
        }

        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->unsignedInteger('group_id')
                ->after('id')
                ->nullable();

            $table->foreign('group_id')->references('id')->on('groups')->onDelete('set null');
        });

        DB::table('twofaccount_group_assignments')
            ->select('twofaccount_id', DB::raw('MIN(group_id) as group_id'))
            ->groupBy('twofaccount_id')
            ->orderBy('twofaccount_id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('twofaccounts')
                        ->where('id', $row->twofaccount_id)
                        ->update(['group_id' => $row->group_id]);
                }
            });
    }

    /**
     * Engine-safe SQLite dropColumn: reconstruct the CREATE TABLE statement
     * from sqlite_master without the target column and its FOREIGN KEY
     * clause, recreate the table, copy the rows, swap the names.
     */
    private function dropColumnSqlite(string $table, string $column) : void
    {
        $create = DB::select("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])[0]->sql;

        // Strip the quoted column definition line, e.g. `"group_id" INTEGER,`
        $recreate = preg_replace('/\s*"' . $column . '"\s+[^,]+,/', '', $create, 1);

        // Strip the FK clause Laravel's grammar emits for the dropped column,
        // e.g. `, foreign key("group_id") references "groups"("id") on delete
        // set null on update no action` — match the full known-shape clause.
        $recreate = preg_replace(
            '/,?\s*(CONSTRAINT\s+"?\w+"?\s+)?foreign\s+key\("' . $column . '"\)\s+references\s+"?[^"(]+"?\("[^"]+"\)(\s+on\s+delete\s+(set\s+null|cascade|restrict|no\s+action|default\s+\w+))?(\s+on\s+update\s+(set\s+null|cascade|restrict|no\s+action|default\s+\w+))?/i',
            '',
            $recreate,
            1
        );

        $tmp      = $table . '_tmp_dropcol';
        $recreate = str_replace('CREATE TABLE "' . $table . '"', 'CREATE TABLE "' . $tmp . '"', $recreate);

        // create the replacement first, copy, then swap the names
        DB::statement($recreate);

        $columns = collect(DB::select("PRAGMA table_info('" . $tmp . "')"))
            ->pluck('name')
            ->reject(fn ($name) => $name === $column)
            ->all();

        $columnList = implode(', ', array_map(fn ($name) => '"' . $name . '"', $columns));

        DB::statement('INSERT INTO ' . $tmp . ' (' . $columnList . ') SELECT ' . $columnList . ' FROM ' . $table);
        DB::statement('DROP TABLE ' . $table);
        DB::statement('ALTER TABLE ' . $tmp . ' RENAME TO ' . $table);
    }
};
