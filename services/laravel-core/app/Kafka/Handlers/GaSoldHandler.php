<?php

namespace App\Kafka\Handlers;

use App\Models\EventOccurrence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GaSoldHandler
{
    public function handle(array $payload): void
    {
        $occurrenceId = $payload['event_occurrence_id'] ?? null;
        $quantity = (int) ($payload['quantity'] ?? 0);
        if (! $occurrenceId || $quantity < 1) {
            Log::warning('ga.sold missing/invalid fields', ['payload' => $payload]);
            return;
        }
        EventOccurrence::where('id', $occurrenceId)->update([
            'capacity_sold' => DB::raw("capacity_sold + {$quantity}"),
            'capacity_held' => DB::raw("GREATEST(capacity_held - {$quantity}, 0)"),
        ]);
    }
}