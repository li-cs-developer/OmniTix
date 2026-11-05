<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReconcileHolds extends Command
{
    protected $signature = 'omnitix:reconcile-holds';
    protected $description = 'Cancel orders whose holds expired and no payment arrived; release any stale Redis holds.';

    public function handle(): int
    {
        $now = now();

        // ─── 1. Orders in `pending_payment` whose hold expired ──
        $stale = Order::query()
            ->where('status', 'pending_payment')
            ->where('hold_expires_at', '<', $now->copy()->subMinutes(2)) // 2-min grace
            ->limit(500)
            ->get();

        $inventoryUrl = env('INVENTORY_HTTP_URL', 'http://host.docker.internal:8080');
        $cancelled = 0;

        foreach ($stale as $order) {
            try {
                // Ask Go to release any seat holds still alive (Redis may have
                // already expired them; Go is idempotent).
                foreach ($order->items as $item) {
                    if ($item->seat_id) {
                        Http::timeout(3)->post("{$inventoryUrl}/api/v1/seats/release", [
                            'token' => '', // token not persisted on OrderItem; Go releases by seat+user
                            'seat_id' => $item->seat_id,
                            'event_occurrence_id' => $order->event_occurrence_id,
                            'user_id' => $order->user_id ?? '',
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('reconcile-holds: release failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::transaction(function () use ($order, $now) {
                $order->update([
                    'status' => 'expired',
                    'cancelled_at' => $now,
                ]);
            });

            $cancelled++;
        }

        // ─── 2. Tickets in `pending` older than 10 minutes ───────
        //        (signing silently failed — retry once)
        $unsigned = Ticket::query()
            ->where('status', 'pending')
            ->where('created_at', '<', $now->copy()->subMinutes(10))
            ->limit(100)
            ->get();

        $signed = 0;
        foreach ($unsigned as $ticket) {
            try {
                app(\App\Services\TicketSigningService::class)->signTicket($ticket);
                $signed++;
            } catch (\Throwable $e) {
                Log::warning('reconcile-holds: re-sign failed', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Reconciled: {$cancelled} orders expired, {$signed} tickets re-signed.");

        Log::info('reconcile-holds complete', [
            'orders_expired' => $cancelled,
            'tickets_resigned' => $signed,
        ]);

        return self::SUCCESS;
    }
}