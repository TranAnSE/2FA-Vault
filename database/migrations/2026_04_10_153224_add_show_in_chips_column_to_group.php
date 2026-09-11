<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from upstream 2FAuth v8 (commit fd97db267, group chips feature).
 *
 * Fork deviation: the column addition is guarded with Schema::hasColumn() so
 * the migration is engine-agnostic and safe to re-run or to apply on a
 * database already carrying the column.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up() : void
    {
        if (! Schema::hasColumn('groups', 'show_in_chips')) {
            Schema::table('groups', function (Blueprint $table) {
                $table->boolean('show_in_chips')
                    ->after('name')
                    ->default(false);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down() : void
    {
        if (Schema::hasColumn('groups', 'show_in_chips')) {
            Schema::table('groups', function (Blueprint $table) {
                $table->dropColumn('show_in_chips');
            });
        }
    }
};
