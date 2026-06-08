<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_limit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('ip_address', 45);
            $table->string('endpoint', 255);
            $table->string('method', 10);
            $table->boolean('was_limited')->default(false);
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index(['ip_address', 'created_at']);
            $table->index(['was_limited', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limit_logs');
    }
};
