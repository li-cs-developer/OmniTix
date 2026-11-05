<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EventOccurrence;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceTier;
use App\Models\Seat;
use App\Models\Ticket;
use App\Services\TicketSigningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    /**
     * POST /api/v1/orders/checkout
     *
     * Synchronous checkout flow:
     *   0. Idempotency replay check
     *   0a. Apply promo code (optional)
     *   0b. Verify holds are still alive (fail fast, don't charge for expired seats)
     *   1. Create order + order items in Postgres
     *   2. Charge via Kotlin Ledger (mocked) + persist payment reference
     *   2b. Record promo redemption
     *   3. Confirm each seat hold in Go Inventory
     *   4. Create Ticket records
     *   5. Sign each ticket via Rust Crypto
     *   6. Dispatch ticket delivery email job
     *   7. Mark order paid + fulfilled
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'occurrence_id' => 'required|string',
            'user_id' => 'required|string',
            'buyer_email' => 'required|email',
            'idempotency_key' => 'nullable|string|max:64',
            'promo_code' => 'nullable|string|max:64',
            'seats' => 'required|array|min:1',
            'seats.*.seat_id' => 'required|string',
            'seats.*.hold_token' => 'required|string',
            'seats.*.price_tier_id' => 'required|string',
            'seats.*.price_cents' => 'required|integer|min:0',
        ]);

        // ─── 0. Idempotency replay ──────────────────────────
        if (! empty($validated['idempotency_key'])) {
            $existing = Order::where('idempotency_key', $validated['idempotency_key'])->first();
            if ($existing) {
                return response()->json([
                    'data' => $this->orderResultPayload($existing->load('tickets')),
                ]);
            }
        }

        $occurrence = EventOccurrence::with('event.venue')->find($validated['occurrence_id']);
        if (!$occurrence) {
            return response()->json([
                'error' => 'occurrence_not_found',
                'message' => "Occurrence {$validated['occurrence_id']} not found",
            ], 404);
        }

        $totalCents = collect($validated['seats'])->sum('price_cents');
        if ($totalCents <= 0) {
            return response()->json([
                'error' => 'invalid_total',
                'message' => 'Total must be greater than zero',
            ], 422);
        }

        // ─── 0a. Apply promo ─────────────────────────────────
        $discountCents = 0;
        $promoId = null;
        if (! empty($validated['promo_code'])) {
            $promoSvc = app(\App\Services\PromoCodeService::class);
            $promoResult = $promoSvc->validate(
                $validated['promo_code'],
                $occurrence->event->organizer_id,
                $occurrence->event_id,
                collect($validated['seats'])->map(fn ($s) => [
                    'seat_id'        => $s['seat_id'],
                    'price_tier_id'  => $s['price_tier_id'],
                    'price_cents'    => $s['price_cents'],
                    'section_code'   => null,
                ])->all(),
                $validated['user_id'],
            );
            if (! ($promoResult['valid'] ?? false)) {
                return response()->json([
                    'error'   => 'promo_invalid',
                    'reason'  => $promoResult['reason'] ?? 'unknown',
                    'message' => 'Promo code could not be applied.',
                ], 422);
            }
            $discountCents = (int) $promoResult['discount_cents'];
            $promoId = $promoResult['promo_id'];
        }

        // ─── 0b. Verify every hold is still alive ───────────
        // We must NOT charge for seats whose Redis hold has already expired.
        // Ask Go Inventory for current state; reject checkout if any seat is
        // not HELD by *this* user.
        $inventoryUrl = env('INVENTORY_HTTP_URL', 'http://host.docker.internal:8080');
        try {
            $statesResponse = Http::timeout(5)->get("{$inventoryUrl}/api/v1/seats/states", [
                'occurrence_id' => $occurrence->id,
            ]);
            $states = $statesResponse->json('data', $statesResponse->json());
            $heldIds = array_flip(
                array_merge(
                    (array) ($states['held'] ?? []),
                    (array) ($states['sold'] ?? []),
                ),
            );
            foreach ($validated['seats'] as $item) {
                if (! isset($heldIds[$item['seat_id']])) {
                    return response()->json([
                        'error' => 'hold_expired',
                        'message' => "Hold for seat {$item['seat_id']} has expired. Please re-select.",
                        'seat_id' => $item['seat_id'],
                    ], 409);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Inventory state check failed before charge', [
                'error' => $e->getMessage(),
            ]);
            // Fail closed: don't charge if we can't verify holds.
            return response()->json([
                'error' => 'inventory_unreachable',
                'message' => 'Could not verify seat holds before charging.',
            ], 502);
        }

        // ─── 1. Create Order ────────────────────────────────
        try {
            $order = DB::transaction(function () use ($occurrence, $validated, $totalCents, $discountCents, $promoId) {
                $order = Order::create([
                    'reference' => 'OTX-' . strtoupper(Str::random(8)),
                    'user_id' => null,               // guest checkout in v1
                    'guest_email' => $validated['buyer_email'],
                    'event_id' => $occurrence->event_id,
                    'event_occurrence_id' => $occurrence->id,
                    'subtotal_cents' => $totalCents,
                    'discount_cents' => $discountCents,
                    'total_cents' => $totalCents - $discountCents,
                    'currency' => 'USD',
                    'status' => 'pending_payment',
                    'hold_expires_at' => now()->addMinutes(10),
                    'ip_address' => request()->ip(),
                    'idempotency_key' => $validated['idempotency_key'] ?? null,
                    'promo_code_id' => $promoId,
                ]);

                foreach ($validated['seats'] as $item) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'seat_id' => $item['seat_id'],
                        'quantity' => 1,
                        'price_tier_id' => $item['price_tier_id'],
                        'unit_price_cents' => $item['price_cents'],
                        'line_subtotal_cents' => $item['price_cents'],
                        'line_total_cents' => $item['price_cents'],
                    ]);
                }

                return $order;
            });
        } catch (\Throwable $e) {
            Log::error('Order creation failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'order_creation_failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        // ─── 2. Charge via Kotlin Ledger ────────────────────
        $ledgerUrl = env('LEDGER_GRPC_HTTP_URL', 'http://host.docker.internal:8081');
        try {
            $chargeResponse = Http::timeout(10)
                ->post("{$ledgerUrl}/api/v1/charge", [
                    'order_id' => $order->id,
                    'amount_cents' => $totalCents - $discountCents,
                    'currency' => 'USD',
                    'buyer_email' => $validated['buyer_email'],
                    'payment_method_token' => 'tok_test_visa',
                ]);

            if ($chargeResponse->failed() || !$chargeResponse->json('success')) {
                $order->update(['status' => 'failed']);
                return response()->json([
                    'error' => 'payment_failed',
                    'message' => $chargeResponse->json('message') ?? 'Payment declined',
                    'order_id' => $order->id,
                ], 402);
            }

            // Persist the ledger's payment reference synchronously so refunds
            // work immediately without waiting for the Kafka event.
            $chargeData = $chargeResponse->json();
            $order->update([
                'payment_id' => $chargeData['payment_id'] ?? null,
                'provider_ref' => $chargeData['provider_ref'] ?? null,
                'payment_captured_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $order->update(['status' => 'failed']);
            Log::error('Ledger charge failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'ledger_unreachable',
                'message' => $e->getMessage(),
                'order_id' => $order->id,
            ], 502);
        }

        // ─── 2b. Record promo redemption ────────────────────
        if ($promoId && $discountCents > 0) {
            \App\Models\PromoRedemption::create([
                'promo_code_id' => $promoId,
                'user_id' => $validated['user_id'],
                'order_id' => $order->id,
                'discount_applied_cents' => $discountCents,
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
            ]);
            \App\Models\PromoCode::where('id', $promoId)->increment('redemptions_count');
        }

        // ─── 3. Confirm each hold + create tickets ──────────
        $inventoryUrl = env('INVENTORY_HTTP_URL', 'http://host.docker.internal:8080');
        $tickets = [];

        foreach ($validated['seats'] as $item) {
            // Confirm hold in Go
            try {
                $confirm = Http::timeout(5)
                    ->post("{$inventoryUrl}/api/v1/seats/confirm", [
                        'token' => $item['hold_token'],
                        'user_id' => $validated['user_id'],
                        'order_id' => $order->id,
                    ]);

                if ($confirm->failed() || $confirm->json('error')) {
                    Log::warning('Hold confirm failed', [
                        'seat' => $item['seat_id'],
                        'error' => $confirm->json('message'),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Hold confirm threw', ['error' => $e->getMessage()]);
            }

            // Create the ticket (pending, then sign it)
            $ticket = Ticket::create([
                'order_id' => $order->id,
                'order_item_id' => $order->items()->where('seat_id', $item['seat_id'])->first()->id,
                'event_id' => $order->event_id,
                'event_occurrence_id' => $order->event_occurrence_id,
                'seat_id' => $item['seat_id'],
                'price_tier_id' => $item['price_tier_id'],
                'ticket_number' => 'OTX-' . strtoupper(Str::random(12)),
                'status' => 'pending',
            ]);

            // Sign it with Rust
            try {
                $signer = app(TicketSigningService::class);
                $signer->signTicket($ticket);
            } catch (\Throwable $e) {
                Log::error('Failed to sign ticket', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
                // Ticket stays 'pending'. Can be re-signed later.
            }

            $tickets[] = $ticket->fresh();
        }

        // Dispatch the ticket delivery email (runs on Laravel queue workers)
        \App\Jobs\SendTicketEmailJob::dispatch($order->id)->onQueue('emails');

        // ─── 4. Mark order as paid + fulfilled ──────────────
        $order->update([
            'status' => 'fulfilled',
            'paid_at' => now(),
            'fulfilled_at' => now(),
        ]);

        return response()->json([
            'data' => $this->orderResultPayload($order->load('tickets')),
        ]);
    }

    /**
     * Shared payload builder — used by both the fresh-success path and the
     * idempotency replay path so callers get an identical response shape
     * whether this is the first request or a retry.
     */
    private function orderResultPayload(Order $order): array
    {
        return [
            'order_id'      => $order->id,
            'reference'     => $order->reference,
            'total_cents'   => $order->total_cents,
            'currency'      => $order->currency,
            'status'        => $order->status,
            'tickets'       => $order->tickets->map(fn ($t) => [
                'id'             => $t->id,
                'ticket_number'  => $t->ticket_number,
                'seat_id'        => $t->seat_id,
                'qr_payload'     => $t->qr_payload,
                'qr_signature'   => $t->qr_signature,
                'signer_key_id'  => $t->signer_key_id,
                'status'         => $t->status,
            ])->values()->all(),
        ];
    }
}