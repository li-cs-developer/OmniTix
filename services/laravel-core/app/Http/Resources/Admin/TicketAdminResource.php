<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'order_id' => $this->order_id,
            'event_id' => $this->event_id,
            'event_occurrence_id' => $this->event_occurrence_id,

            'seat_id' => $this->seat_id,
            'seat_label' => $this->whenLoaded('seat', fn () => $this->seat->display_label),
            'section' => $this->whenLoaded('seat', fn () => $this->seat->section),
            'row' => $this->whenLoaded('seat', fn () => $this->seat->row_label),
            'seat_number' => $this->whenLoaded('seat', fn () => $this->seat->seat_number),

            'price_tier_id' => $this->price_tier_id,
            'tier_name' => $this->whenLoaded('priceTier', fn () => $this->priceTier->name),

            'buyer_email' => $this->whenLoaded('order', fn () => $this->order->guest_email),
            'buyer_name' => $this->attendee_name,

            'status' => $this->status,
            'is_refunded' => $this->is_refunded,
            'scanned_at' => $this->scanned_at?->toIso8601String(),
            'scan_count' => $this->scan_count,

            'qr_issued_at' => $this->qr_issued_at?->toIso8601String(),
            'signer_key_id' => $this->signer_key_id,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}