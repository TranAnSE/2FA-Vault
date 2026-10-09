<?php

namespace App\Models;

use App\Support\TeamPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamRole extends Model
{
    protected $fillable = [
        'team_id',
        'slug',
        'name',
        'permissions',
        'is_system',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_system'   => 'boolean',
    ];

    /**
     * Get the team this role belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team() : BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Seed the 4 system preset roles for a team. Idempotent — the
     * unique [team_id, slug] key makes re-seeding a no-op.
     */
    public static function seedSystemPresets(Team $team) : void
    {
        $now = now();

        foreach (TeamPermission::systemPresets() as $slug => $permissions) {
            self::query()->updateOrCreate(
                ['team_id' => $team->id, 'slug' => $slug],
                [
                    'name'        => ucfirst($slug),
                    'permissions' => $permissions,
                    'is_system'   => true,
                ],
            );
        }
    }
}
