<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * GET /api/v1/admin/dashboard/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $membership = $request->attributes->get('organizer_membership');
        $organizerId = $membership->organizer_id;

        // Event IDs for this organizer
        $eventIds = Event::query()
            ->where('organizer_id', $organizerId)
            ->pluck('id');

        $occurrenceIds = EventOccurrence::query()
            ->whereIn('event_id', $eventIds)
            ->pluck('id');

        // ─── Orders ─────────────────────────────────────────
        $ordersBase = Order::query()->whereIn('event_id', $eventIds);

        $ordersTotal = (clone $ordersBase)->count();
        $ordersFulfilled = (clone $ordersBase)->where('status', 'fulfilled')->count();
        $ordersRefunded = (clone $ordersBase)->where('status', 'refunded')->count();

        // ─── Revenue ────────────────────────────────────────
        $grossRevenue = (int) (clone $ordersBase)
            ->where('status', 'fulfilled')
            ->sum('total_cents');

        $netRevenue = (int) (clone $ordersBase)
            ->where('status', 'fulfilled')
            ->select(DB::raw('COALESCE(SUM(total_cents), 0) AS total'))
            ->value('total');

        // ─── Tickets ────────────────────────────────────────
        $ticketsBase = Ticket::query()->whereIn('event_occurrence_id', $occurrenceIds);

        $ticketsTotal = (clone $ticketsBase)->count();
        $ticketsIssued = (clone $ticketsBase)->where('status', 'issued')->count();
        $ticketsScanned = (clone $ticketsBase)->where('status', 'scanned')->count();
        $ticketsVoided = (clone $ticketsBase)->where('status', 'voided')->count();

        // ─── Events ─────────────────────────────────────────
        $eventsTotal = (clone $eventIds)->count();
        $eventsOnSale = Event::query()
            ->where('organizer_id', $organizerId)
            ->where('status', 'on_sale')
            ->count();

        // ─── Recent sales (last 24h) ────────────────────────
        $recentRevenue = (int) (clone $ordersBase)
            ->where('status', 'fulfilled')
            ->where('paid_at', '>=', now()->subDay())
            ->sum('total_cents');

        return response()->json([
            'data' => [
                'organizer_id' => $organizerId,

                'events' => [
                    'total' => $eventsTotal,
                    'on_sale' => $eventsOnSale,
                ],

                'orders' => [
                    'total' => $ordersTotal,
                    'fulfilled' => $ordersFulfilled,
                    'refunded' => $ordersRefunded,
                ],

                'tickets' => [
                    'total' => $ticketsTotal,
                    'issued' => $ticketsIssued,
                    'scanned' => $ticketsScanned,
                    'voided' => $ticketsVoided,
                ],

                'revenue' => [
                    'gross_cents' => $grossRevenue,
                    'net_cents' => $netRevenue,
                    'last_24h_cents' => $recentRevenue,
                    'currency' => 'USD',
                ],
            ],
        ]);
    }
}