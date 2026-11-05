<?php

namespace App\Kafka\Handlers;

use App\Models\EventOccurrence;
use Illuminate\Support\Facades\DB;

class GaHeldHandler
{
    public function handle(array $payload): void
    {
        $occurrenceId = $payload['event_occurrence_id'] ?? null;
        $quantity = (int) ($payload['quantity'] ?? 0);
        if (! $occurrenceId || $quantity < 1) return;

        EventOccurrence::where('id', $occurrenceId)->update([
            'capacity_held' => DB::raw("capacity_held + {$quantity}"),
        ]);
    }
}