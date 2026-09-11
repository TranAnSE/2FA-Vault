<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

/**
 * Ported from upstream 2FAuth #563 (commits 8a55b2e4 + bc3d4d4a).
 *
 * Fork deviation: column and index drops are guarded with Schema::hasColumn()
 * / Schema::hasIndex() so the migration is engine-agnostic and safe to re-run
 * or to apply on a database already (partially) on the Passport 13 schema.
 */
return new class extends Migration
{
    public function up() : void
    {
        if (! Schema::hasColumn('oauth_clients', 'owner_type')) {
            Schema::table('oauth_clients', function (Blueprint $table) {
                $table->nullableMorphs('owner', after: 'user_id');
            });
        }

        if (! Schema::hasColumn('oauth_clients', 'grant_types')) {
            Schema::table('oauth_clients', function (Blueprint $table) {
                $table->after('provider', function (Blueprint $table) {
                    $table->text('redirect_uris')->nullable();
                    $table->text('grant_types')->nullable();
                });
            });
        }

        // Backfill legacy client rows so existing tokens (incl. PATs) keep working.
        if (Schema::hasColumn('oauth_clients', 'user_id')) {
            foreach (Passport::client()->cursor() as $client) {
                Model::withoutTimestamps(fn () => $client->forceFill([
                    'owner_id'   => $client->user_id,
                    'owner_type' => $client->user_id
                        ? config('auth.providers.' . ($client->provider ?: config('auth.guards.api-guard.provider')) . '.model')
                        : null,
                    'redirect_uris' => $client->redirect_uris,
                    'grant_types'   => ['personal_access', 'refresh_token'],
                ])->save());
            }
        }

        if (Schema::hasIndex('oauth_clients', 'oauth_clients_user_id_index')) {
            Schema::table('oauth_clients', function (Blueprint $table) {
                $table->dropIndex('oauth_clients_user_id_index');
            });
        }

        $legacyColumns = array_values(array_filter(
            ['user_id', 'redirect', 'personal_access_client', 'password_client'],
            fn (string $column) => Schema::hasColumn('oauth_clients', $column)
        ));

        Schema::table('oauth_clients', function (Blueprint $table) use ($legacyColumns) {
            if ($legacyColumns !== []) {
                $table->dropColumn($legacyColumns);
            }

            $table->text('redirect_uris')->nullable(false)->change();
            $table->text('grant_types')->nullable(false)->change();
        });

        Schema::dropIfExists('oauth_personal_access_clients');
    }

    public function down() : void
    {
        $legacyColumns = array_values(array_filter(
            ['user_id', 'redirect', 'personal_access_client', 'password_client'],
            fn (string $column) => ! Schema::hasColumn('oauth_clients', $column)
        ));

        Schema::table('oauth_clients', function (Blueprint $table) use ($legacyColumns) {
            foreach ($legacyColumns as $column) {
                match ($column) {
                    'user_id' => $table->unsignedBigInteger('user_id')->nullable()->index()->after('id'),
                    // Nullable first: SQLite refuses NOT NULL adds without default
                    // on non-empty tables; the change() block below flips them
                    // back to NOT NULL once the backfill has filled every row.
                    'redirect'               => $table->text('redirect')->nullable()->after('provider'),
                    'personal_access_client' => $table->boolean('personal_access_client')->nullable()->after('redirect'),
                    'password_client'        => $table->boolean('password_client')->nullable()->after('personal_access_client'),
                };
            }
        });

        if (Schema::hasColumn('oauth_clients', 'owner_id')) {
            foreach (Passport::client()->cursor() as $client) {
                Model::withoutTimestamps(fn () => $client->forceFill([
                    'user_id'                => $client->owner_id,
                    'redirect'               => implode(',', $client->redirect_uris ?? []),
                    'personal_access_client' => in_array('personal_access', $client->grant_types ?? []),
                    'password_client'        => in_array('password', $client->grant_types ?? []),
                ])->save());
            }
        }

        Schema::table('oauth_clients', function (Blueprint $table) {
            if (Schema::hasColumn('oauth_clients', 'redirect')) {
                $table->text('redirect')->nullable(false)->change();
            }

            if (Schema::hasColumn('oauth_clients', 'personal_access_client')) {
                $table->boolean('personal_access_client')->nullable(false)->change();
            }

            if (Schema::hasColumn('oauth_clients', 'password_client')) {
                $table->boolean('password_client')->nullable(false)->change();
            }

            $table->dropMorphs('owner');

            $newColumns = array_values(array_filter(
                ['redirect_uris', 'grant_types'],
                fn (string $column) => Schema::hasColumn('oauth_clients', $column)
            ));

            if ($newColumns !== []) {
                $table->dropColumn($newColumns);
            }
        });

        if (! Schema::hasTable('oauth_personal_access_clients')) {
            Schema::create('oauth_personal_access_clients', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('client_id');
                $table->timestamps();
            });
        }
    }
};
