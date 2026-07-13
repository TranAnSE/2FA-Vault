<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a previous_owner_id column to twofaccounts so that direct account
 * ownership transfers can retain an audit trail of the prior owner.
 */
return new class extends Migration
{
    public function up() : void
    {
        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->foreignId('previous_owner_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down() : void
    {
        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->dropForeign(['previous_owner_id']);
            $table->dropColumn('previous_owner_id');
        });
    }
};
