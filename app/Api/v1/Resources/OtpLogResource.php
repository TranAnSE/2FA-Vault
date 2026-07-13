<?php

namespace App\Api\v1\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
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
class OtpLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'             => $this->id,
            'requester_id'   => $this->requester_id,
            'owner_id'       => $this->owner_id,
            'twofaccount_id' => $this->twofaccount_id,
            'otp_type'       => $this->otp_type,
            'counter'        => $this->counter,
            // IP and user-agent are sensitive; surface only to the account owner
            // or an admin.
            'ip_address' => $this->when(
                $request->user()->is_admin || $request->user()->id === $this->owner_id,
                $this->ip_address
            ),
            'user_agent' => $this->when(
                $request->user()->is_admin || $request->user()->id === $this->owner_id,
                $this->user_agent
            ),
            'generated_at' => $this->generated_at->toIso8601String(),
        ];
    }
}
