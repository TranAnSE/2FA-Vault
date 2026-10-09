<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen team_users.role and team_invitations.role from the (owner, admin,
     * member, viewer) enum to string(50) so they can hold custom role slugs
     * (v1.4.0 per-team role matrix).
     *
     * MySQL note: this runs one ALTER per table; on large team_users tables
     * the ALTER takes a metadata lock — schedule the upgrade accordingly and
     * take a DB backup beforehand (release notes requirement).
     */
    public function up() : void
    {
        Schema::table('team_users', function (Blueprint $table) {
            $table->string('role', 50)->default('member')->change();
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->string('role', 50)->default('member')->change();
        });
    }

    public function down() : void
    {
        // Defensive remap (RT-13): custom role slugs cannot be re-narrowed
        // into the 4-value enum — strict mode would hard-fail the rollback,
        // non-strict mode would silently blank them. Fold them onto 'member'
        // (the least-privilege system role) first.
        $systemRoles = ['owner', 'admin', 'member', 'viewer'];

        DB::table('team_users')->whereNotIn('role', $systemRoles)->update(['role' => 'member']);
        DB::table('team_invitations')->whereNotIn('role', $systemRoles)->update(['role' => 'member']);

        Schema::table('team_users', function (Blueprint $table) {
            $table->enum('role', ['owner', 'admin', 'member', 'viewer'])->default('member')->change();
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->enum('role', ['owner', 'admin', 'member', 'viewer'])->default('member')->change();
        });
    }
};
