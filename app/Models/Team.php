<?php

namespace App\Models;

use App\Support\TeamPermission;
use App\Support\TeamPermissionSet;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Team extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Every newly created team gets its system preset roles (the permission
     * matrix) — seeded here so ALL creation paths (factory, service, direct
     * model create) produce a complete matrix. Idempotent via the
     * unique [team_id, slug] key.
     */
    protected static function booted() : void
    {
        static::created(fn (Team $team) => TeamRole::seedSystemPresets($team));
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'owner_id',
        'invite_code',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the owner of the team.
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get all users in the team.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'team_users')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    /**
     * Get all shared accounts in the team.
     */
    public function sharedAccounts()
    {
        return $this->hasMany(SharedAccount::class);
    }

    /**
     * Get all invitations for this team.
     */
    public function invitations()
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get the activity log entries for this team.
     */
    public function activityLogs()
    {
        return $this->hasMany(TeamActivityLog::class);
    }

    /**
     * Get the role definitions (permission matrix rows) of this team.
     *
     * @return HasMany<TeamRole, $this>
     */
    public function roles()
    {
        return $this->hasMany(TeamRole::class);
    }

    /**
     * The single authorization lookup for the whole team surface: resolve
     * the user's effective permission set.
     *
     * The OWNER short-circuit keys STRICTLY on owner_id — never on the
     * pivot role string (RT-1): a poisoned `team_users.role = 'owner'` row
     * must confer nothing beyond its team_roles definition. Owner-locked
     * capabilities (team.delete, roles.manage, activity.export, ownership
     * transfer) are not resolvable through the matrix at all.
     *
     * Non-members and dangling role slugs (a role deleted while a racing
     * invitation was accepted) fail CLOSED to the empty set — never an
     * exception (RT-15); a dangling slug is logged as a warning.
     */
    public function permissionsFor(User $user) : TeamPermissionSet
    {
        if ($this->owner_id === $user->id) {
            return new TeamPermissionSet(TeamPermission::all());
        }

        $membership = $this->users()->where('users.id', $user->id)->first();

        if (! $membership) {
            return new TeamPermissionSet([]);
        }

        $role = $this->roles()->where('slug', $membership->pivot->role)->first();

        if (! $role) {
            Log::warning('Team role slug has no definition — failing closed', [
                'team_id' => $this->id,
                'slug'    => $membership->pivot->role,
                'user_id' => $user->id,
            ]);

            return new TeamPermissionSet([]);
        }

        return new TeamPermissionSet(array_values((array) $role->permissions));
    }

    /**
     * Scope a query to only include teams accessible by a specific user.
     */
    public function scopeAccessibleByUser($query, $userId)
    {
        // B14: group the orWhere in a closure so the SoftDeletes global scope
        // (whereNull deleted_at) still applies to the whole query — an ungrouped
        // orWhere('owner_id') made soft-deleted teams match for their owner.
        return $query->where(function ($q) use ($userId) {
            $q->whereHas('users', function ($sq) use ($userId) {
                $sq->where('users.id', $userId);
            })->orWhere('owner_id', $userId);
        });
    }

    /**
     * Generate a unique invite code.
     */
    public function generateInviteCode() : string
    {
        do {
            $code = Str::random(16);
        } while (self::where('invite_code', $code)->exists());

        $this->invite_code = $code;
        $this->save();

        return $code;
    }

    /**
     * Check if a user is a member of this team.
     */
    public function hasMember($userId) : bool
    {
        return $this->users()->where('users.id', $userId)->exists() || $this->owner_id == $userId;
    }

    /**
     * Get the role of a user in this team.
     */
    public function getUserRole($userId) : ?string
    {
        if ($this->owner_id == $userId) {
            return 'owner';
        }

        $membership = $this->users()->where('users.id', $userId)->first();

        return $membership ? $membership->pivot->role : null;
    }
}
