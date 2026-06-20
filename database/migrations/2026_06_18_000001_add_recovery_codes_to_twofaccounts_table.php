<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an encrypted, nullable recovery_codes column to twofaccounts.
 *
 * Stores a JSON string array of the user's external-service backup codes
 * (e.g. GitHub's 10 codes). Encrypted server-side via Laravel Crypt in the
 * non-E2EE path, or stored as the client ciphertext blob when E2EE is on —
 * identical to the existing `notes` column. Reversible storage is required
 * because the user must view the codes later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->text('recovery_codes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->dropColumn('recovery_codes');
        });
    }
};
