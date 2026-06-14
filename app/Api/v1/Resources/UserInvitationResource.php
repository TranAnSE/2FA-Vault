<?php

namespace App\Api\v1\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property string $email
 * @property string $role
 * @property \Illuminate\Support\Carbon|null $accepted_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class UserInvitationResource extends JsonResource
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
            'id'          => $this->id,
            'email'       => $this->email,
            'role'        => $this->role,
            'expires_at'  => $this->expires_at ? $this->expires_at->toIso8601String() : null,
            'accepted_at' => $this->accepted_at ? $this->accepted_at->toIso8601String() : null,
            'created_at'  => $this->created_at ? $this->created_at->toIso8601String() : null,
            'is_expired'  => $this->isExpired(),
            'is_pending'  => $this->isPending(),
        ];
    }
}
