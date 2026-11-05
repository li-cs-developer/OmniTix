<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'hero_image_url' => $this->hero_image_url,
            'gallery' => $this->gallery ?? [],
            'category' => $this->category,
            'tags' => $this->tags ?? [],
            'performer_name' => $this->performer_name,
            'performer_bio' => $this->performer_bio,
            'age_restriction' => $this->age_restriction,
            'status' => $this->status,

            'sales_start_at' => $this->sales_start_at?->toIso8601String(),
            'sales_end_at' => $this->sales_end_at?->toIso8601String(),

            'max_tickets_per_user' => $this->max_tickets_per_user,
            'max_tickets_per_card' => $this->max_tickets_per_card,
            'refund_cutoff_hours' => $this->refund_cutoff_hours,
            'allow_refunds_after_start' => $this->allow_refunds_after_start,

            'settings' => $this->settings ?? [],

            'venue' => $this->whenLoaded('venue', fn () => [
                'id' => $this->venue->id,
                'name' => $this->venue->name,
                'city' => $this->venue->city,
            ]),
            'seat_map' => $this->whenLoaded('seatMap', fn () => [
                'id' => $this->seatMap->id,
                'version' => $this->seatMap->version,
                'name' => $this->seatMap->name,
            ]),
            'occurrences_count' => $this->when(
                $this->relationLoaded('occurrences'),
                fn () => $this->occurrences->count(),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}