<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $nextOccurrence = $this->occurrences->first();

        // Compute min/max pricing from event tier pricing
        $prices = $this->tierPricing
            ->pluck('price_cents')
            ->filter()
            ->sort()
            ->values();

        $minPrice = $prices->first();
        $maxPrice = $prices->last();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'short_description' => $this->short_description,
            'hero_image_url' => $this->hero_image_url,
            'category' => $this->category,
            'status' => $this->status,
            'sales_start_at' => $this->sales_start_at?->toIso8601String(),
            'sales_end_at' => $this->sales_end_at?->toIso8601String(),
            'venue' => [
                'id' => $this->venue->id,
                'name' => $this->venue->name,
                'city' => $this->venue->city,
            ],
            'next_occurrence' => $nextOccurrence ? [
                'id' => $nextOccurrence->id,
                'starts_at' => $nextOccurrence->starts_at->toIso8601String(),
                'doors_open_at' => $nextOccurrence->doors_open_at?->toIso8601String(),
            ] : null,
            'min_price_cents' => $minPrice,
            'max_price_cents' => $maxPrice,
        ];
    }
}