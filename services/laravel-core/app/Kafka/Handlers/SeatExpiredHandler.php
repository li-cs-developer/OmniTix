<?php

namespace App\Kafka\Handlers;

use App\Models\EventOccurrence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SeatExpiredHandler
{
    public function handle(array $payload): void
    {
        $occurrenceId = $payload['event_occurrence_id'] ?? null;
        if (! $occurrenceId) {
            Log::warning('seat.expired missing event_occurrence_id');
            return;
        }
        EventOccurrence::where('id', $occurrenceId)->update([
            'capacity_held' => DB::raw('GREATEST(capacity_held - 1, 0)'),
        ]);
    }
}