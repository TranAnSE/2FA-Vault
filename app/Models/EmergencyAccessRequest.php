<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyAccessRequest extends Model
{
    protected $fillable = [
        'contact_id', 'requester_id', 'status',
        'requested_at', 'responded_at', 'granted_at',
    ];

    protected $casts = [
        'requested_at'  => 'datetime',
        'responded_at'  => 'datetime',
        'granted_at'    => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(EmergencyContact::class, 'contact_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }
}
