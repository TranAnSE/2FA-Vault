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
        // Create teams table
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->string('invite_code')->unique()->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('owner_id');
            $table->index('invite_code');
        });

        // Create team_users pivot table
        Schema::create('team_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('role', ['owner', 'admin', 'member', 'viewer'])->default('member');
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();
            
            $table->unique(['team_id', 'user_id']);
            $table->index('team_id');
            $table->index('user_id');
        });

        // Create shared_accounts table
        Schema::create('shared_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            // twofaccounts.id is a legacy INT increments() column (upstream 2019 schema);
            // MySQL rejects a BIGINT foreign key against it (error 3780), so this column
            // must stay unsignedInteger. SQLite (tests) does not enforce the type.
            $table->unsignedInteger('twofaccount_id');
            $table->foreign('twofaccount_id')->references('id')->on('twofaccounts')->onDelete('cascade');
            $table->foreignId('shared_by')->constrained('users')->onDelete('cascade');
            $table->string('access_level')->default('read'); // read, write, admin
            $table->text('encrypted_key')->nullable(); // Team-encrypted secret key
            $table->timestamps();
            
            $table->index('team_id');
            $table->index('twofaccount_id');
            $table->index('shared_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shared_accounts');
        Schema::dropIfExists('team_users');
        Schema::dropIfExists('teams');
    }
};
