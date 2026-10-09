<?php

use App\Models\Team;
use App\Support\TeamPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-team role matrix substrate: a role is a row holding a permission
     * set. System presets are seeded for every existing team so v1.3.x-era
     * teams exhibit identical authorization behavior after the upgrade
     * (behavior-compat gate RT-8).
     */
    public function up() : void
    {
        // Pre-flight guard (RT-13): MySQL DDL is non-transactional — a
        // half-applied migration that later failed would leave the table
        // behind and a re-run would die on the duplicate create.
        if (! Schema::hasTable('team_roles')) {
            Schema::create('team_roles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
                $table->string('slug', 50);
                $table->string('name');
                $table->json('permissions');
                $table->boolean('is_system')->default(false);
                $table->timestamps();

                $table->unique(['team_id', 'slug']);
            });
        }

        // Seed the system presets for every existing team (chunked; the
        // unique [team_id, slug] key keeps re-runs idempotent).
        $presets = TeamPermission::systemPresets();

        Team::query()->withTrashed()->select('id')->chunkById(500, function ($teams) use ($presets) {
            $rows = [];
            $now  = now();

            foreach ($teams as $team) {
                foreach ($presets as $slug => $permissions) {
                    $rows[] = [
                        'team_id'     => $team->id,
                        'slug'        => $slug,
                        'name'        => ucfirst($slug),
                        'permissions' => json_encode($permissions),
                        'is_system'   => true,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ];
                }
            }

            if ($rows !== []) {
                DB::table('team_roles')->insertOrIgnore($rows);
            }
        });
    }

    public function down() : void
    {
        if (Schema::hasTable('team_roles')) {
            Schema::dropIfExists('team_roles');
        }
    }
};
