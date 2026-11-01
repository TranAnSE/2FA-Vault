<?php

namespace App\Api\v1\Resources;

use App\Models\Dto\HotpDto;
use App\Models\Dto\TotpDto;
use Illuminate\Http\Request;

/**
 * @property mixed $id
 * @method int|null groupIdForUser(\App\Models\User $user)
 *
 * @method \App\Models\Dto\TotpDto|\App\Models\Dto\HotpDto getOtp(int $time)
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\TwoFAccountUserFavorite> $favorites
 */
class TwoFAccountReadResource extends TwoFAccountStoreResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $groupId = $this->groupIdForUser($request->user());
        // is_favorite is the requester's own pivot membership. Computed from
        // the eager-loaded favorites relation (index/show/encrypted load it);
        // hasFavorite() is the fallback for resources built without the load.
        $isFavorite = $this->resource->relationLoaded('favorites')
            ? $this->favorites->contains('user_id', $request->user()?->id)
            : (bool) $request->user()?->hasFavorite($this->resource);

        return array_merge(
            [
                'id'           => (int) $this->id,
                'group_id'     => is_null($groupId) ? null : (int) $groupId,
                'last_used_at' => $this->last_used_at?->toISOString(),
                'tags'         => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                    'id'    => $tag->id,
                    'name'  => $tag->name,
                    'color' => $tag->color,
                ])->values()),
                'is_favorite' => $isFavorite,
            ],
            parent::toArray($request),
            [
                'otp' => $this->when(
                    $this->otp_type != 'hotp' && ($request->has('withOtp') || (int) filter_var($request->input('withOtp'), FILTER_VALIDATE_BOOLEAN) == 1),
                    function () use ($request) {
                        /**
                         * @var TotpDto|HotpDto
                         */
                        $otp = $this->getOtp($request->at);

                        return collect([
                            'password'      => $otp->password,
                            'generated_at'  => $otp->generated_at,
                            'next_password' => $otp->next_password,
                        ]);
                    }
                ),
            ],
        );
    }
}
