<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Encryption enabled flag (null = not set up, true = setup complete)
            $table->boolean('encryption_enabled')->default(false)->after('password');
            
            // Salt for Argon2id key derivation (base64 encoded, 32 bytes)
            $table->string('encryption_salt', 255)->nullable()->after('encryption_enabled');
            
            // Encrypted test value for zero-knowledge password verification
            $table->text('encryption_test_value')->nullable()->after('encryption_salt');
            
            // Encryption version for future compatibility (0 = not set up)
            $table->tinyInteger('encryption_version')->default(0)->after('encryption_test_value');
            
            // Vault lock status (session-based, unlocked by default)
            $table->boolean('vault_locked')->default(false)->after('encryption_version');
            
            // Track last backup date
            $table->timestamp('last_backup_at')->nullable()->after('vault_locked');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'encryption_enabled',
                'encryption_salt',
                'encryption_test_value',
                'encryption_version',
                'vault_locked',
                'last_backup_at'
            ]);
        });
    }
};
