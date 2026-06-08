<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RateLimitLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'ip_address', 'endpoint',
        'method', 'was_limited', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'was_limited' => 'boolean',
        'created_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
