<?php

namespace App\Http\Resources;

use App\Models\BackupSnapshot;
use App\Services\BackupSnapshotService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A backup snapshot in list form. Carries everything the restore wizard
 * needs — there is deliberately no separate GET /{id} detail endpoint
 * (RT-15 cut).
 *
 * @mixin BackupSnapshot
 */
class BackupSnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BackupSnapshotService $service */
        $service = app(BackupSnapshotService::class);

        return [
            'id' => $this->id,
            'source' => $this->source,
            'label' => $this->label,
            'accounts_count' => $this->accounts_count,
            'groups_count' => $this->groups_count,
            'size_bytes' => $this->size_bytes,
            'created_at' => $this->created_at?->toIso8601String(),
            // Master-password rotation signal (compare against the requester).
            'salt_changed_since_snapshot' => $service->saltChangedSince($this->resource, $request->user()),
            // APP_KEY rotation signal — clean flag, never a 500 (RT-13).
            'unreadable_reason' => $service->unreadableReason($this->resource),
        ];
    }
}
