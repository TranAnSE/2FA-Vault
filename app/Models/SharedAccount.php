<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SharedAccount extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'twofaccount_id',
        'shared_by',
        'access_level',
        'encrypted_key',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the team that owns this shared account.
     */
    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the 2FA account being shared.
     */
    public function twoFAccount()
    {
        return $this->belongsTo(TwoFAccount::class, 'twofaccount_id');
    }

    /**
     * Get the user who shared this account.
     */
    public function sharedBy()
    {
        return $this->belongsTo(User::class, 'shared_by');
    }
}
