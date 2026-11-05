<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromoCodeAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organizer_id' => $this->organizer_id,
            'event_id' => $this->event_id,
            'code' => $this->code,
            'description' => $this->description,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'max_discount_cents' => $this->max_discount_cents,
            'max_redemptions' => $this->max_redemptions,
            'max_redemptions_per_user' => $this->max_redemptions_per_user,
            'redemptions_count' => $this->redemptions_count,
            'applicable_sections' => $this->applicable_sections ?? [],
            'applicable_tier_ids' => $this->applicable_tier_ids ?? [],
            'min_order_cents' => $this->min_order_cents,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}