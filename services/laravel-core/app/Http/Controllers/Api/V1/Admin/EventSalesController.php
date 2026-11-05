<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventSalesController extends Controller
{
    /**
     * GET /api/v1/admin/events/{id}/sales
     *
     * Returns per-occurrence sales breakdown + per-tier totals.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $membership = $request->attributes->get('organizer_membership');

        $event = Event::query()
            ->where('id', $id)
            ->where('organizer_id', $membership->organizer_id)
            ->with(['occurrences', 'tierPricing.tier'])
            ->first();

        if (! $event) abort(404, 'Event not found.');

        // ─── Orders for this event ──────────────────────────
        $orders = Order::query()->where('event_id', $event->id);

        $ordersTotal = (clone $orders)->count();
        $ordersFulfilled = (clone $orders)->where('status', 'fulfilled')->count();

        $grossRevenue = (int) (clone $orders)
            ->where('status', 'fulfilled')
            ->sum('total_cents');

        $refundedTotal = (int) (clone $orders)
            ->where('status', 'refunded')
            ->sum('total_cents');

        // ─── Per-occurrence breakdown ───────────────────────
        $occurrenceIds = $event->occurrences->pluck('id');

        $perOccurrence = DB::table('orders')
            ->whereIn('event_occurrence_id', $occurrenceIds)
            ->where('status', 'fulfilled')
            ->select('event_occurrence_id', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(total_cents) as revenue_cents'))
            ->groupBy('event_occurrence_id')
            ->get()
            ->keyBy('event_occurrence_id');

        $occurrenceBreakdown = $event->occurrences->map(function ($occ) use ($perOccurrence) {
            $data = $perOccurrence->get($occ->id);
            return [
                'id' => $occ->id,
                'label' => $occ->label,
                'starts_at' => $occ->starts_at?->toIso8601String(),
                'capacity_total' => $occ->capacity_total,
                'capacity_sold' => $occ->capacity_sold,
                'capacity_held' => $occ->capacity_held,
                'orders_count' => $data->order_count ?? 0,
                'revenue_cents' => (int) ($data->revenue_cents ?? 0),
            ];
        })->values();

        // ─── Per-tier breakdown ─────────────────────────────
        $perTier = DB::table('tickets')
            ->whereIn('event_occurrence_id', $occurrenceIds)
            ->where('status', '!=', 'voided')
            ->select('price_tier_id', DB::raw('COUNT(*) as ticket_count'))
            ->groupBy('price_tier_id')
            ->get()
            ->keyBy('price_tier_id');

        $tierBreakdown = $event->tierPricing->map(function ($pricing) use ($perTier) {
            $tierId = $pricing->price_tier_id;
            $data = $perTier->get($tierId);
            $count = (int) ($data->ticket_count ?? 0);

            return [
                'tier_id' => $tierId,
                'tier_name' => $pricing->tier?->name,
                'price_cents' => $pricing->price_cents,
                'tickets_sold' => $count,
                'revenue_cents' => $count * $pricing->price_cents,
            ];
        })->values();

        return response()->json([
            'data' => [
                'event_id' => $event->id,
                'event_title' => $event->title,

                'summary' => [
                    'orders_total' => $ordersTotal,
                    'orders_fulfilled' => $ordersFulfilled,
                    'gross_revenue_cents' => $grossRevenue,
                    'refunded_cents' => $refundedTotal,
                    'net_revenue_cents' => $grossRevenue - $refundedTotal,
                    'currency' => 'USD',
                ],

                'occurrences' => $occurrenceBreakdown,
                'price_tiers' => $tierBreakdown,
            ],
        ]);
    }
}