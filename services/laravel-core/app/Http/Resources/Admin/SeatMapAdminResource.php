<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeatMapAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'venue_id' => $this->venue_id,
            'version' => $this->version,
            'name' => $this->name,
            'notes' => $this->notes,
            'width' => $this->width,
            'height' => $this->height,
            'meta' => $this->meta ?? [],
            'is_published' => (bool) $this->is_published,
            'seats_count' => $this->when(
                $this->relationLoaded('seats'),
                fn () => $this->seats->count(),
            ),
            'ga_zones_count' => $this->when(
                $this->relationLoaded('gaZones'),
                fn () => $this->gaZones->count(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}