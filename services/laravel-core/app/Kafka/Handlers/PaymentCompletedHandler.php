<?php

namespace App\Kafka\Handlers;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

class PaymentCompletedHandler
{
    public function handle(array $payload): void
    {
        $orderId = $payload['order_id'] ?? null;
        $paymentId = $payload['payment_id'] ?? null;
        $providerRef = $payload['provider_ref'] ?? null;
        $amountCents = $payload['amount_cents'] ?? 0;

        if (! $orderId) {
            Log::warning('payment.completed missing order_id', ['payload' => $payload]);
            return;
        }

        $order = Order::where('id', $orderId)->first();
        if (! $order) {
            Log::warning('payment.completed for unknown order', ['order_id' => $orderId]);
            return;
        }

        // Idempotent: already processed
        if ($order->payment_id === $paymentId && $order->status === 'fulfilled') {
            Log::debug('payment.completed already processed', ['order_id' => $orderId]);
            return;
        }

        $updates = [
            'payment_id' => $paymentId,
            'provider_ref' => $providerRef,
            'payment_captured_at' => now(),
        ];

        // Only promote the status if we're still before "paid".
        // This prevents a late payment.completed event from re-marking
        // a refunded or cancelled order as fulfilled.
        if (in_array($order->status, ['draft', 'pending_payment'], true)) {
            $updates['status'] = 'fulfilled';
            $updates['paid_at'] = $order->paid_at ?? now();
            $updates['fulfilled_at'] = $order->fulfilled_at ?? now();
        }

        $order->update($updates);

        Log::info('Order updated via Kafka (payment.completed)', [
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'amount_cents' => $amountCents,
            'status' => $order->fresh()->status,
        ]);
    }
}