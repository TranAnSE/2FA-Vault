<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per OTP generation request.
 *
 * The requester is the user who asked for the OTP; the owner is the account
 * owner. They match for a user's own accounts and differ for shared accounts
 * (see the Hybrid Sharing phase). This duality lets the audit log attribute
 * both "who generated" and "whose secret was used".
 *
 * @property int $id
 * @property int $requester_id
 * @property int $owner_id
 * @property int|null $twofaccount_id
 * @property string|null $otp_type
 * @property int|null $counter
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $generated_at
 */
class OtpLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'generated_at' => 'datetime',
    ];

    public function requester() : BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function owner() : BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function twofaccount() : BelongsTo
    {
        return $this->belongsTo(TwoFAccount::class);
    }
}
