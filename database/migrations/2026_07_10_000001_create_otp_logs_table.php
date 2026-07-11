<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores one row per OTP generation request, with a requester/owner duality so
 * that OTP generation for a shared (borrowed) account can be attributed both to
 * the user who requested it and to the account owner. Mirrors upstream 2FAuth
 * v7.0.0 otp_logs schema, adapted to the fork's naming conventions.
 */
return new class extends Migration
{
    public function up() : void
    {
        Schema::create('otp_logs', function (Blueprint $table) {
            $table->bigIncrements('id');

            // The user who requested the OTP (always set).
            $table->foreignId('requester_id')->constrained('users')->onDelete('cascade');

            // The account owner. Equals requester_id for non-shared accounts;
            // differs when a member of a team generates an OTP for a shared
            // account (set up fully in Đợt 5 Hybrid Sharing).
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');

            $table->foreignId('twofaccount_id')->nullable()->constrained()->onDelete('cascade');

            $table->string('otp_type', 10)->nullable();
            $table->unsignedInteger('counter')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('generated_at')->useCurrent();

            $table->index(['requester_id', 'generated_at']);
            $table->index(['owner_id', 'generated_at']);
            $table->index(['twofaccount_id']);
            $table->index('ip_address');
            $table->index('otp_type');
        });
    }

    public function down() : void
    {
        Schema::dropIfExists('otp_logs');
    }
};
