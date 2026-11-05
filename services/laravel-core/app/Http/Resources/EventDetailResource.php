<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summary = (new EventSummaryResource($this->resource))->toArray($request);

        return array_merge($summary, [
            'description' => $this->description,
            'age_restriction' => $this->age_restriction,
            'performer_name' => $this->performer_name,
            'max_tickets_per_user' => $this->max_tickets_per_user,
            'venue' => [
                'id' => $this->venue->id,
                'name' => $this->venue->name,
                'city' => $this->venue->city,
                'state' => $this->venue->state,
                'timezone' => $this->venue->timezone,
            ],
            'seat_map' => [
                'id' => $this->seatMap->id,
                'width' => $this->seatMap->width,
                'height' => $this->seatMap->height,
                'meta' => $this->seatMap->meta ?? [],
                'ga_zones' => $this->seatMap->gaZones->map(fn ($z) => [
                    'id' => $z->id,
                    'code' => $z->code,
                    'name' => $z->name,
                    'capacity' => $z->capacity,
                    'visual' => $z->visual ?? [],
                ])->values()->all(),
            ],
            'sections' => $this->venue->sections->map(fn ($s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'kind' => $s->kind,
                'visual' => $s->visual ?? [],
            ])->values(),
            'price_tiers' => $this->tierPricing->map(fn ($tp) => [
                'id' => $tp->tier->id,
                'name' => $tp->tier->name,
                'price_cents' => $tp->price_cents,
                'currency' => $tp->currency,
                'color' => $tp->tier->color,
            ])->values(),
        ]);
    }
}