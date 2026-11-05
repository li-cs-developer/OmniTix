<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OrderAdminResource;
use App\Models\Event;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $membership = $request->attributes->get('organizer_membership');

        $eventIds = Event::query()
            ->where('organizer_id', $membership->organizer_id)
            ->pluck('id');

        $query = Order::query()->whereIn('event_id', $eventIds);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($eventId = $request->query('event_id')) {
            $query->where('event_id', $eventId);
        }
        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'ilike', "%{$search}%")
                  ->orWhere('guest_email', 'ilike', "%{$search}%");
            });
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => OrderAdminResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $membership = $request->attributes->get('organizer_membership');

        $order = Order::query()
            ->where('id', $id)
            ->whereHas('event', fn ($q) => $q->where('organizer_id', $membership->organizer_id))
            ->with(['items.seat', 'items.priceTier', 'tickets.seat', 'tickets.priceTier'])
            ->first();

        if (! $order) abort(404, 'Order not found.');

        $data = (new OrderAdminResource($order))->toArray($request);

        $data['items'] = $order->items->map(fn ($item) => [
            'id' => $item->id,
            'seat_id' => $item->seat_id,
            'seat_label' => $item->seat?->display_label,
            'price_tier_id' => $item->price_tier_id,
            'tier_name' => $item->priceTier?->name,
            'unit_price_cents' => $item->unit_price_cents,
            'quantity' => $item->quantity,
            'line_total_cents' => $item->line_total_cents,
        ])->values();

        $data['tickets'] = $order->tickets->map(fn ($t) => [
            'id' => $t->id,
            'ticket_number' => $t->ticket_number,
            'seat_label' => $t->seat?->display_label,
            'status' => $t->status,
            'scanned_at' => $t->scanned_at?->toIso8601String(),
        ])->values();

        return response()->json(['data' => $data]);
    }
}