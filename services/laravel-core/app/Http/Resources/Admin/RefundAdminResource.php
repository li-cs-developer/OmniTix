<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'payment_id' => $this->payment_id,
            'issued_by_user_id' => $this->issued_by_user_id,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'provider_ref' => $this->provider_ref,
            'affected_ticket_ids' => $this->affected_ticket_ids ?? [],
            'status' => $this->status,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}