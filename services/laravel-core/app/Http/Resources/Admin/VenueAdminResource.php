<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VenueAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organizer_id' => $this->organizer_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'timezone' => $this->timezone,
            'total_capacity' => $this->total_capacity,
            'settings' => $this->settings ?? [],
            'sections' => $this->whenLoaded('sections', fn () =>
                $this->sections->map(fn ($s) => [
                    'id' => $s->id,
                    'code' => $s->code,
                    'name' => $s->name,
                    'kind' => $s->kind,
                    'visual' => $s->visual ?? [],
                    'display_order' => $s->display_order,
                    'is_active' => (bool) $s->is_active,
                ])->values()
            ),
            'seat_maps_count' => $this->when(
                $this->relationLoaded('seatMaps'),
                fn () => $this->seatMaps->count(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}