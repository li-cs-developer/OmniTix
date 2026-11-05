<?php

namespace App\Kafka\Handlers;

use App\Models\EventOccurrence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SeatSoldHandler
{
    public function handle(array $payload): void
    {
        $occurrenceId = $payload['event_occurrence_id'] ?? null;
        if (! $occurrenceId) {
            Log::warning('seat.sold missing event_occurrence_id');
            return;
        }
        EventOccurrence::where('id', $occurrenceId)->update([
            'capacity_sold' => DB::raw('capacity_sold + 1'),
            'capacity_held' => DB::raw('GREATEST(capacity_held - 1, 0)'),
        ]);
    }
}