<?php

namespace App\Api\v1\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property string $action
 * @property array|null $metadata
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property int|null $target_account_id
 * @property \Illuminate\Support\Carbon $created_at
 */
class PersonalActivityLogResource extends JsonResource
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
            'id'               => $this->id,
            'action'           => $this->action,
            'metadata'         => $this->metadata,
            'ip_address'       => $this->when($request->user()->is_admin, $this->ip_address),
            'user_agent'       => $this->when($request->user()->is_admin, $this->user_agent),
            'target_account_id' => $this->target_account_id,
            'created_at'       => $this->created_at->toIso8601String(),
        ];
    }
}
