<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('trusted_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('email');
            $table->enum('status', ['pending', 'confirmed', 'active', 'revoked'])->default('pending');
            $table->enum('access_type', ['view_only', 'full_access'])->default('view_only');
            $table->unsignedSmallInteger('wait_days')->default(30);
            $table->text('encrypted_key')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_id', 'email']);
        });

        Schema::create('emergency_access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('emergency_contacts')->onDelete('cascade');
            $table->foreignId('requester_id')->constrained('users');
            $table->enum('status', ['pending', 'approved', 'denied', 'auto_granted'])->default('pending');
            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_access_requests');
        Schema::dropIfExists('emergency_contacts');
    }
};
