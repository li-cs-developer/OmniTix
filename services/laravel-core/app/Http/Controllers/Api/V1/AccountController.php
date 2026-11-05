<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Guest-order access via `guest_email` + order reference. No login required
 * in v1; if a user registers later, we can migrate these to real accounts.
 *
 * Any action requires proof-of-ownership: reference + email must match.
 */
class AccountController extends Controller
{
    /**
     * POST /api/v1/account/lookup
     * Body: { email }
     *
     * Returns a list of order references owned by this email (limited
     * metadata — no tickets, no QR — just references for the lookup UI).
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $orders = Order::where('guest_email', $validated['email'])
            ->whereIn('status', ['fulfilled', 'partially_refunded', 'refunded'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'reference', 'total_cents', 'currency', 'status', 'created_at', 'event_id']);

        return response()->json([
            'data' => $orders->map(fn ($o) => [
                'order_id' => $o->id,
                'reference' => $o->reference,
                'total_cents' => $o->total_cents,
                'currency' => $o->currency,
                'status' => $o->status,
                'placed_at' => $o->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * GET /api/v1/account/orders/{reference}?email=...
     */
    public function order(Request $request, string $reference): JsonResponse
    {
        $email = $request->query('email');
        if (! $email) {
            return response()->json(['error' => 'email_required'], 400);
        }

        $order = Order::with([
            'event.venue',
            'occurrence',
            'tickets.seat',
            'tickets.priceTier',
        ])
            ->where('reference', $reference)
            ->where('guest_email', $email)
            ->first();

        if (! $order) {
            return response()->json(['error' => 'order_not_found'], 404);
        }

        return response()->json([
            'data' => [
                'order_id' => $order->id,
                'reference' => $order->reference,
                'status' => $order->status,
                'total_cents' => $order->total_cents,
                'currency' => $order->currency,
                'placed_at' => $order->created_at?->toIso8601String(),
                'event' => $order->event ? [
                    'id' => $order->event->id,
                    'title' => $order->event->title,
                    'slug' => $order->event->slug,
                    'venue_name' => $order->event->venue?->name,
                ] : null,
                'occurrence' => $order->occurrence ? [
                    'id' => $order->occurrence->id,
                    'starts_at' => $order->occurrence->starts_at?->toIso8601String(),
                    'doors_open_at' => $order->occurrence->doors_open_at?->toIso8601String(),
                ] : null,
                'tickets' => $order->tickets->map(fn ($t) => [
                    'id' => $t->id,
                    'ticket_number' => $t->ticket_number,
                    'seat_label' => $t->seat?->display_label,
                    'tier_name' => $t->priceTier?->name,
                    'status' => $t->status,
                    'qr_payload' => $t->qr_payload,
                    'qr_signature' => $t->qr_signature,
                    'signer_key_id' => $t->signer_key_id,
                ])->values(),
            ],
        ]);
    }
}