<?php

namespace App\Api\v1\Resources;

use App\Models\TwoFAccountUserFavorite;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property mixed $id
 * @property-read Collection<int, TwoFAccountUserFavorite> $favorites
 */
class EncryptedTwoFAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'          => (int) $this->id,
            'group_id'    => is_null($this->group_id) ? null : (int) $this->group_id,
            'service'     => $this->service,
            'account'     => $this->account,
            'otp_type'    => $this->otp_type,
            'secret'      => $this->secret,
            'encrypted'   => (bool) $this->encrypted,
            'digits'      => (int) $this->digits,
            'algorithm'   => $this->algorithm,
            'period'      => is_null($this->period) ? null : (int) $this->period,
            'counter'     => is_null($this->counter) ? null : (int) $this->counter,
            'notes'       => $this->notes,
            'is_pinned'   => (bool) $this->is_pinned,
            'is_favorite' => $this->resource->relationLoaded('favorites')
                ? $this->favorites->contains('user_id', $request->user()?->id)
                : (bool) $request->user()?->hasFavorite($this->resource),
            'recovery_codes' => $this->recovery_codes,
        ];
    }
}
