<?php

namespace App\Kafka\Handlers;

use App\Models\Ticket;
use App\Models\TicketScan;
use Illuminate\Support\Facades\Log;

class TicketScannedHandler
{
    public function handle(array $payload): void
    {
        $ticketId = $payload['ticket_id'] ?? null;
        $result = $payload['scan_result'] ?? 'unknown';
        $gate = $payload['gate_identifier'] ?? null;
        $scannedAt = $payload['scanned_at'] ?? now()->toIso8601String();

        if (! $ticketId) {
            Log::warning('ticket.scanned missing ticket_id');
            return;
        }

        $ticket = Ticket::find($ticketId);
        if (! $ticket) {
            Log::warning('ticket.scanned for unknown ticket', ['ticket_id' => $ticketId]);
            return;
        }

        TicketScan::create([
            'ticket_id' => $ticketId,
            'gate_identifier' => $gate,
            'result' => $result,
            'scanned_at' => $scannedAt,
            'context' => [],
        ]);

        if ($result === 'accepted') {
            $ticket->update([
                'status' => 'scanned',
                'scanned_at' => $scannedAt,
                'scan_count' => $ticket->scan_count + 1,
            ]);
        } else {
            $ticket->increment('scan_count');
        }

        Log::info('Ticket scan recorded', [
            'ticket_id' => $ticketId,
            'result' => $result,
            'gate' => $gate,
        ]);
    }
}