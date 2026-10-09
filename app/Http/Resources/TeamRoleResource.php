<?php

namespace App\Http\Resources;

use App\Models\TeamRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TeamRole
 */
class TeamRoleResource extends JsonResource
{
    /**
     * Transform the resource into a JSON array. `members_count` is set on the
     * model by the controller (one grouped query for the whole collection).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request) : array
    {
        return [
            'id'            => $this->id,
            'slug'          => $this->slug,
            'name'          => $this->name,
            'permissions'   => $this->permissions ?? [],
            'is_system'     => (bool) $this->is_system,
            'members_count' => $this->members_count ?? 0,
        ];
    }
}
