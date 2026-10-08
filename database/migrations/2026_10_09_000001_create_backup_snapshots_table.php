<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Server-side backup snapshot store (v1.4.0). Row-level dumps of a user's
     * accounts and groups as stored (E2EE secrets stay ciphertext), kept
     * APP_KEY-encrypted at rest on the dedicated `snapshots` disk.
     */
    public function up(): void
    {
        // MySQL DDL is non-transactional: a half-failed CREATE TABLE must not
        // brick `migrate` on re-run (RT-13).
        if (Schema::hasTable('backup_snapshots')) {
            return;
        }

        Schema::create('backup_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // manual | pre_restore | scheduled — drives lane-aware quota eviction.
            $table->enum('source', ['manual', 'pre_restore', 'scheduled']);
            $table->string('label')->nullable();
            $table->unsignedInteger('accounts_count')->default(0);
            $table->unsignedInteger('groups_count')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);
            // sha256 of the encrypted file bytes, verified on load.
            $table->string('checksum', 64);
            // Master-password rotation detection: the vault salt/version at
            // snapshot time vs the user's current values (restore warns on
            // mismatch, mirrors the import key_mismatch_warning pattern).
            $table->text('encryption_salt_at_snapshot')->nullable();
            $table->unsignedTinyInteger('encryption_version_at_snapshot')->nullable();
            // Short sha256 prefix of APP_KEY at write time — a rotation makes
            // the snapshot unreadable; list/load surfaces it as
            // `unreadable_reason: app_key_rotated` instead of a 500 (RT-13).
            $table->string('app_key_fingerprint', 16);
            $table->string('file_path');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        // Orphaned encrypted files on the snapshots disk are reconciled by
        // `snapshots:prune` (CleanupBackupFiles only sweeps the backups disk).
        Schema::dropIfExists('backup_snapshots');
    }
};
