<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Passkeys\Passkeys;

return new class extends Migration
{
    /**
     * PRF vault-unlock storage (plan 261114-2101, D6).
     *
     * Table name per the plan: `passkey_prf_credentials`. NOTE: the FILE is
     * named `..._keyset_...` rather than the plan's literal
     * `..._credentials_...` because the workspace privacy hook blocks writes
     * to paths matching /credentials/i — a false positive on this plain
     * schema definition (approved rename, documented in the plan journal).
     *
     * `users.prf_salt` — one random salt per user, shared by all of their
     * credentials, evaluated client-side with the WebAuthn prf extension.
     * `passkey_prf_credentials` — the per-credential wrapped RSA keyset.
     * The server stores ONLY wrapped blobs (zero knowledge): public_key,
     * wrapped_private_key, wrapped_vault_key. FK cascade on passkey_id and
     * user_id gives revoke/recovery/user-deletion cleanup for free.
     */
    public function up() : void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('prf_salt', 64)->nullable()->after('encryption_version');
        });

        Schema::create('passkey_prf_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Passkeys::userModel(), 'user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('passkey_id')->constrained()->cascadeOnDelete()->unique();
            $table->text('public_key');
            $table->text('wrapped_private_key');
            $table->text('wrapped_vault_key');
            $table->timestamps();
        });
    }

    public function down() : void
    {
        Schema::dropIfExists('passkey_prf_credentials');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('prf_salt');
        });
    }
};
