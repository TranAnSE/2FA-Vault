<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vault extends Model
{
    protected $fillable = [
        'user_id', 'name',
        'encryption_salt', 'encryption_test_value', 'encryption_version',
        'is_default', 'is_locked', 'last_opened_at',
    ];

    protected $casts = [
        'is_default'     => 'boolean',
        'is_locked'      => 'boolean',
        'last_opened_at' => 'datetime',
    ];

    protected $hidden = ['encryption_salt', 'encryption_test_value'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(TwoFAccount::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }
}
