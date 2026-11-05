<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\RefundAdminResource;
use App\Models\Event;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RefundController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $membership = $request->attributes->get('organizer_membership');

        $eventIds = Event::query()
            ->where('organizer_id', $membership->organizer_id)
            ->pluck('id');

        $orderIds = Order::query()->whereIn('event_id', $eventIds)->pluck('id');

        $query = Refund::query()->whereIn('order_id', $orderIds);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => RefundAdminResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function forOrder(Request $request, string $orderId): JsonResponse
    {
        $order = $this->findOrderForOrganizer($request, $orderId);

        $refunds = Refund::where('order_id', $order->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => RefundAdminResource::collection($refunds),
        ]);
    }

    public function store(Request $request, string $orderId): JsonResponse
    {
        $order = $this->findOrderForOrganizer($request, $orderId);

        if (! in_array($order->status, ['fulfilled', 'partially_refunded'], true)) {
            return response()->json([
                'error' => 'order_not_refundable',
                'message' => "Order status is '{$order->status}'. Only fulfilled orders can be refunded.",
            ], 409);
        }

        $validated = $request->validate([
            'ticket_ids' => 'nullable|array',
            'ticket_ids.*' => 'string',
            'reason' => 'nullable|string|max:500',
        ]);

        $ticketsQuery = Ticket::where('order_id', $order->id)
            ->where('status', 'issued');

        if (! empty($validated['ticket_ids'])) {
            $ticketsQuery->whereIn('id', $validated['ticket_ids']);
        }

        $tickets = $ticketsQuery->get();

        if ($tickets->isEmpty()) {
            return response()->json([
                'error' => 'no_refundable_tickets',
                'message' => 'No refundable tickets found on this order.',
            ], 422);
        }

        // Compute refund amount from matching order items
        $amountCents = (int) DB::table('order_items')
            ->where('order_id', $order->id)
            ->whereIn('seat_id', $tickets->pluck('seat_id')->filter()->all())
            ->sum('line_total_cents');

        if ($amountCents === 0) {
            $amountCents = (int) round(
                $order->total_cents / max(1, $order->items()->count())
                * $tickets->count()
            );
        }

        // ─── 1. Reverse the charge via Kotlin ───────────────
        $ledgerUrl = env('LEDGER_GRPC_HTTP_URL', 'http://host.docker.internal:8082');
        $refundId = (string) Str::ulid();

        try {
            $resp = Http::timeout(10)
                ->post("{$ledgerUrl}/api/v1/refund", [
                    'refund_id'    => $refundId,
                    'payment_id'   => $order->payment_id,
                    'order_id'     => $order->id,
                    'amount_cents' => $amountCents,
                    'currency'     => $order->currency ?? 'USD',
                    'reason'       => $validated['reason'] ?? 'Admin refund',
                    'affected_ticket_ids' => $tickets->pluck('id')->all(),
                ]);

            if ($resp->failed()) {
                return response()->json([
                    'error' => 'ledger_refund_failed',
                    'message' => 'Ledger service rejected the refund.',
                    'detail' => $resp->json(),
                ], 502);
            }
        } catch (\Throwable $e) {
            Log::error('Ledger refund call failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'ledger_unreachable',
                'message' => $e->getMessage(),
            ], 502);
        }

        // ─── 2. Void tickets + update order status ──────────
        // The ledger is the source of truth for refunds. It publishes
        // `refund.issued` on success; `RefundIssuedHandler` writes the Refund
        // row + voids tickets. We do NOT write the Refund row here — that
        // would race against the Kafka consumer.
        //
        // We do however void the tickets synchronously so the customer's
        // QR is invalid immediately (worst case: a refund event is replayed,
        // which the handler dedupes by refund_id).
        DB::transaction(function () use ($order, $tickets) {
            Ticket::whereIn('id', $tickets->pluck('id'))
                ->update([
                    'status' => 'voided',
                    'is_refunded' => true,
                    'refunded_at' => now(),
                ]);

            $remaining = Ticket::where('order_id', $order->id)
                ->where('status', 'issued')
                ->count();

            $order->update([
                'status' => $remaining === 0 ? 'refunded' : 'partially_refunded',
                'refunded_at' => $remaining === 0 ? now() : $order->refunded_at,
            ]);
        });

        Log::info('Refund issued', [
            'order_id' => $order->id,
            'refund_id' => $refundId,
            'amount_cents' => $amountCents,
            'ticket_count' => $tickets->count(),
        ]);

        return response()->json([
            'data' => [
                'refund_id' => $refundId,
                'status' => 'succeeded',
                'amount_cents' => $amountCents,
                'currency' => $order->currency ?? 'USD',
                'affected_ticket_ids' => $tickets->pluck('id')->all(),
                // NB: the full Refund resource becomes visible via
                // GET /api/v1/admin/orders/{id}/refunds once the Kafka
                // consumer has caught up (usually <100ms).
            ],
        ], 201);
    }

    private function findOrderForOrganizer(Request $request, string $id): Order
    {
        $membership = $request->attributes->get('organizer_membership');

        $order = Order::query()
            ->where('id', $id)
            ->whereHas('event', fn ($q) => $q->where('organizer_id', $membership->organizer_id))
            ->first();

        if (! $order) abort(404, 'Order not found.');

        return $order;
    }
}