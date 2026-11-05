<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,

            'event_id' => $this->event_id,
            'event_occurrence_id' => $this->event_occurrence_id,
            'user_id' => $this->user_id,
            'guest_email' => $this->guest_email,

            'subtotal_cents' => $this->subtotal_cents,
            'discount_cents' => $this->discount_cents,
            'service_fee_cents' => $this->service_fee_cents,
            'facility_fee_cents' => $this->facility_fee_cents,
            'processing_fee_cents' => $this->processing_fee_cents,
            'tax_cents' => $this->tax_cents,
            'total_cents' => $this->total_cents,
            'currency' => $this->currency,

            'paid_at' => $this->paid_at?->toIso8601String(),
            'fulfilled_at' => $this->fulfilled_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'items_count' => $this->when(
                $this->relationLoaded('items'),
                fn () => $this->items->count(),
            ),
            'tickets_count' => $this->when(
                $this->relationLoaded('tickets'),
                fn () => $this->tickets->count(),
            ),
        ];
    }
}