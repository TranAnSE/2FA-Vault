<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('color', 7)->default('#3273dc');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            $table->unique(['name', 'user_id']);
        });

        Schema::create('account_tag', function (Blueprint $table) {
            $table->foreignId('twofaccount_id')->constrained('twofaccounts')->onDelete('cascade');
            $table->foreignId('tag_id')->constrained()->onDelete('cascade');
            $table->primary(['twofaccount_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_tag');
        Schema::dropIfExists('tags');
    }
};
