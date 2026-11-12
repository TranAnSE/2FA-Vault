<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops the legacy laragear webauthn_credentials table after the v1.6.0
     * one-time ETL (plan 261009-passkeys-migration C4). The drop is guarded:
     * it refuses to run while legacy rows exist and the passkeys table holds
     * nothing, because a blind 1.5 -> 1.6.1 jump would otherwise permanently
     * destroy user data (every registered authenticator). In that state the
     * deployment must first upgrade to v1.6.0 and run the ETL.
     */
    public function up() : void
    {
        if (! Schema::hasTable('webauthn_credentials')) {
            return;
        }

        $legacyRows = (int) DB::table('webauthn_credentials')->count();

        $passkeysAvailable = Schema::hasTable('passkeys') && (int) DB::table('passkeys')->count() > 0;

        if ($legacyRows > 0 && ! $passkeysAvailable) {
            throw new \RuntimeException(sprintf(
                'Aborting: webauthn_credentials still holds %d credential(s) and the passkeys table is empty. '
                . 'Upgrading straight from 1.5.x to 1.6.1 would destroy every registered authenticator. '
                . 'Deploy v1.6.0 first and run "php artisan 2fauth:migrate-webauthn-credentials" (or boot the '
                . 'container once, the v1.6.0 entrypoint runs the ETL automatically) before retrying the 1.6.1 migration.',
                $legacyRows
            ));
        }

        Schema::drop('webauthn_credentials');
    }

    /**
     * Recreates the legacy table SCHEMA only: dropped rows are unrecoverable.
     * Rollback therefore restores a compatible empty table instead of
     * breaking the migration chain.
     */
    public function down() : void
    {
        if (Schema::hasTable('webauthn_credentials')) {
            return;
        }

        Schema::create('webauthn_credentials', function ($table) {
            // Verbatim mirror of the 2022_10_20 laragear blueprint
            // (defaultBlueprint) so a 1.6.1 -> 1.6.0 rollback keeps the
            // rollback path fully functional.
            $table->string('id', 510)->primary();

            $table->morphs('authenticatable', 'webauthn_user_index');

            $table->uuid('user_id');

            $table->string('alias')->nullable();

            $table->unsignedBigInteger('counter')->nullable();

            $table->string('rp_id');

            $table->string('origin');

            $table->json('transports')->nullable();

            $table->uuid('aaguid')->nullable();

            $table->text('public_key');

            $table->string('attestation_format')->default('none');

            $table->json('certificates')->nullable();

            $table->timestamp('disabled_at')->nullable();

            $table->timestamps();
        });
    }
};
