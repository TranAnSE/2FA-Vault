<?php

namespace App\Models;

use App\Enums\TeamAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'team_id',
        'user_id',
        'action',
        'metadata',
        'target_user_id',
        'target_account_id',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function targetAccount(): BelongsTo
    {
        return $this->belongsTo(TwoFAccount::class, 'target_account_id');
    }
}
