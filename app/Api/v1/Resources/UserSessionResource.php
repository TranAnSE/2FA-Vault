<?php

namespace App\Api\v1\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property string $token_id
 * @property string $ip_address
 * @property string $user_agent
 * @property \Illuminate\Support\Carbon $last_active_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class UserSessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        // Correlate to the current request's session (same-origin SPA carries the
        // Laravel session cookie). Safe-guarded: API/stateless requests have none.
        try {
            $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;
        } catch (\Throwable $e) {
            $currentSessionId = null;
        }

        return [
            'id'             => $this->id,
            'token_id'       => $this->token_id ? (substr($this->token_id, 0, 8) . '…') : null,
            'is_current'     => $currentSessionId !== null && $currentSessionId === $this->token_id,
            'ip_address'     => $this->ip_address,
            'user_agent'     => $this->user_agent,
            'last_active_at' => $this->last_active_at ? $this->last_active_at->toIso8601String() : null,
            'created_at'     => $this->created_at->toIso8601String(),
        ];
    }
}
