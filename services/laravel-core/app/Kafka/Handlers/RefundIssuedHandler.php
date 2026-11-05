<?php

namespace App\Kafka\Handlers;

use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefundIssuedHandler
{
    public function handle(array $payload): void
    {
        $orderId = $payload['order_id'] ?? null;
        $refundId = $payload['refund_id'] ?? null;
        $amountCents = $payload['amount_cents'] ?? 0;
        $affectedTicketIds = $payload['affected_ticket_ids'] ?? [];

        if (! $orderId || ! $refundId) {
            Log::warning('refund.issued missing identifiers', ['payload' => $payload]);
            return;
        }

        // Idempotent write: the controller may have already voided the
        // tickets synchronously. firstOrCreate avoids the unique-PK
        // violation if the event is replayed. lockForUpdate on the order
        // serializes concurrent refund events for the same order so the
        // "remaining tickets" count is consistent.
        DB::transaction(function () use (
            $orderId, $refundId, $amountCents, $affectedTicketIds, $payload
        ) {
            $order = Order::where('id', $orderId)->lockForUpdate()->first();
            if (! $order) {
                Log::warning('refund.issued for unknown order', ['order_id' => $orderId]);
                return;
            }

            Refund::firstOrCreate(
                ['id' => $refundId],
                [
                    'order_id' => $orderId,
                    'amount_cents' => $amountCents,
                    'currency' => $payload['currency'] ?? 'USD',
                    'reason' => $payload['reason'] ?? 'External refund',
                    'provider_ref' => $payload['provider_ref'] ?? null,
                    'affected_ticket_ids' => $affectedTicketIds,
                    'status' => 'succeeded',
                    'processed_at' => now(),
                ],
            );

            if (! empty($affectedTicketIds)) {
                Ticket::whereIn('id', $affectedTicketIds)
                    ->update([
                        'status' => 'voided',
                        'is_refunded' => true,
                        'refunded_at' => now(),
                    ]);
            }

            $remaining = Ticket::where('order_id', $orderId)
                ->where('status', 'issued')
                ->count();

            $order->update([
                'status' => $remaining === 0 ? 'refunded' : 'partially_refunded',
                'refunded_at' => $remaining === 0 ? now() : $order->refunded_at,
            ]);
        });

        Log::info('Refund recorded via Kafka', [
            'order_id' => $orderId,
            'refund_id' => $refundId,
            'amount_cents' => $amountCents,
        ]);
    }
}