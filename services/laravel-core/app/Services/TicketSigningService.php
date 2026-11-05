<?php

namespace App\Services;

use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TicketSigningService
{
    /**
     * Sign a ticket payload via the Rust crypto service.
     * Updates the ticket with qr_payload, qr_signature, signer_key_id.
     *
     * @throws \RuntimeException if signing fails
     */
    public function signTicket(Ticket $ticket): void
    {
        $cryptoUrl = env('CRYPTO_HTTP_URL', 'http://host.docker.internal:50054');

        // Build the canonical payload the Rust service expects.
        // Field names match the Rust TicketPayload struct: t, n, e, o, s, g, p, u, i, x.
        $payload = [
            'ticket_id'            => $ticket->id,
            'ticket_number'        => $ticket->ticket_number,
            'event_id'             => $ticket->event_id,
            'event_occurrence_id'  => $ticket->event_occurrence_id,
            'seat_id'              => $ticket->seat_id ?? '',
            'ga_zone_id'           => $ticket->ga_zone_id ?? '',
            'price_tier_id'        => $ticket->price_tier_id,
            'buyer_user_id'        => $ticket->user_id ?? '',
            'issued_at'            => now()->toIso8601String(),
            'nonce'                => bin2hex(random_bytes(16)),
        ];

        try {
            $response = Http::timeout(5)
                ->post("{$cryptoUrl}/api/v1/sign", $payload);

            if ($response->failed()) {
                throw new \RuntimeException(
                    'Crypto service returned ' . $response->status() . ': ' . $response->body(),
                );
            }

            $data = $response->json();
            if (! $data || ! isset($data['qr_payload'], $data['signature'], $data['key_id'])) {
                throw new \RuntimeException('Crypto response missing expected fields');
            }

            $ticket->update([
                'qr_payload'    => $data['qr_payload'],
                'qr_signature'  => $data['signature'],
                'signer_key_id' => $data['key_id'],
                'qr_issued_at'  => now(),
                'status'        => 'issued',
            ]);

            Log::info('Ticket signed', [
                'ticket_id' => $ticket->id,
                'key_id'    => $data['key_id'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Ticket signing failed', [
                'ticket_id' => $ticket->id,
                'error'     => $e->getMessage(),
            ]);
            throw new \RuntimeException('Ticket signing failed: ' . $e->getMessage());
        }
    }
}