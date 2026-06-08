<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name', 100);
            $table->text('encryption_salt')->nullable();
            $table->text('encryption_test_value')->nullable();
            $table->unsignedTinyInteger('encryption_version')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_locked')->default(true);
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });

        // Add vault_id to accounts, groups, tags (nullable = belongs to default vault)
        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->foreignId('vault_id')->nullable()->after('user_id')->constrained()->onDelete('set null');
        });
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('vault_id')->nullable()->after('user_id')->constrained()->onDelete('set null');
        });
        Schema::table('tags', function (Blueprint $table) {
            $table->foreignId('vault_id')->nullable()->after('user_id')->constrained()->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('tags', fn ($t) => $t->dropForeign(['vault_id']));
        Schema::table('groups', fn ($t) => $t->dropForeign(['vault_id']));
        Schema::table('twofaccounts', fn ($t) => $t->dropForeign(['vault_id']));
        Schema::table('tags', fn ($t) => $t->dropColumn('vault_id'));
        Schema::table('groups', fn ($t) => $t->dropColumn('vault_id'));
        Schema::table('twofaccounts', fn ($t) => $t->dropColumn('vault_id'));
        Schema::dropIfExists('vaults');
    }
};
