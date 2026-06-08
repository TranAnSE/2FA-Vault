<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('member_id')->nullable()->after('shared_by');
            $table->text('wrapped_key')->nullable()->after('encrypted_key');
            $table->foreign('member_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('shared_accounts', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
            $table->dropColumn(['member_id', 'wrapped_key']);
        });
    }
};
