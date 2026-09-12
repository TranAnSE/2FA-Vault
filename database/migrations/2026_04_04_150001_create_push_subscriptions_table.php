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
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            // string(500), not text: endpoint is part of the (user_id, endpoint) unique
            // index and MySQL forbids TEXT in key specifications without a key length.
            // Real push-service endpoint URLs stay well under 500 chars.
            $table->string('endpoint', 500);
            $table->string('p256dh');
            $table->string('auth');
            $table->string('content_encoding')->default('aes128gcm');
            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->unique(['user_id', 'endpoint']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
