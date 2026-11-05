<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTierPricing;
use App\Models\PriceTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventTierPricingController extends Controller
{
    public function index(Request $request, string $eventId): JsonResponse
    {
        $event = $this->findEvent($request, $eventId);

        return response()->json([
            'data' => $event->tierPricing()->with('tier')->get()->map(fn ($p) => [
                'id'                    => $p->id,
                'event_id'              => $p->event_id,
                'price_tier_id'         => $p->price_tier_id,
                'tier_name'             => $p->tier?->name,
                'tier_code'             => $p->tier?->code,
                'applicable_sections'   => $p->applicable_sections ?? [],
                'price_cents'           => $p->price_cents,
                'currency'              => $p->currency,
                'service_fee_cents'     => $p->service_fee_cents,
                'facility_fee_cents'    => $p->facility_fee_cents,
                'processing_fee_cents'  => $p->processing_fee_cents,
                'tax_rate'              => $p->tax_rate,
                'tax_inclusive'         => $p->tax_inclusive,
                'sale_starts_at'        => $p->sale_starts_at?->toIso8601String(),
                'sale_ends_at'          => $p->sale_ends_at?->toIso8601String(),
                'quantity_cap'          => $p->quantity_cap,
                'display_order'         => $p->display_order,
                'is_active'             => (bool) $p->is_active,
            ])->values(),
        ]);
    }

    public function store(Request $request, string $eventId): JsonResponse
    {
        $event = $this->findEvent($request, $eventId);

        $validated = $request->validate([
            'price_tier_id'        => [
                'required', 'string',
                Rule::exists('price_tiers', 'id')->where('organizer_id', $event->organizer_id),
                Rule::unique('event_tier_pricing', 'price_tier_id')->where('event_id', $event->id),
            ],
            'applicable_sections'  => 'nullable|array',
            'applicable_sections.*' => 'string|max:32',
            'price_cents'          => 'required|integer|min:0',
            'currency'             => 'sometimes|string|size:3',
            'service_fee_cents'    => 'sometimes|integer|min:0',
            'facility_fee_cents'   => 'sometimes|integer|min:0',
            'processing_fee_cents' => 'sometimes|integer|min:0',
            'tax_rate'             => 'sometimes|numeric|between:0,1',
            'tax_inclusive'        => ['sometimes', Rule::in(['inclusive', 'exclusive'])],
            'sale_starts_at'       => 'nullable|date',
            'sale_ends_at'         => 'nullable|date|after:sale_starts_at',
            'quantity_cap'         => 'nullable|integer|min:0',
            'display_order'        => 'sometimes|integer|min:0',
            'is_active'            => 'sometimes|boolean',
        ]);

        $pricing = $event->tierPricing()->create($validated);

        return response()->json([
            'data' => $pricing->load('tier'),
        ], 201);
    }

    public function update(Request $request, string $eventId, string $pricingId): JsonResponse
    {
        $event = $this->findEvent($request, $eventId);
        $pricing = $event->tierPricing()->where('id', $pricingId)->firstOrFail();

        $validated = $request->validate([
            'applicable_sections'  => 'sometimes|nullable|array',
            'price_cents'          => 'sometimes|integer|min:0',
            'service_fee_cents'    => 'sometimes|integer|min:0',
            'facility_fee_cents'   => 'sometimes|integer|min:0',
            'processing_fee_cents' => 'sometimes|integer|min:0',
            'tax_rate'             => 'sometimes|numeric|between:0,1',
            'tax_inclusive'        => ['sometimes', Rule::in(['inclusive', 'exclusive'])],
            'sale_starts_at'       => 'sometimes|nullable|date',
            'sale_ends_at'         => 'sometimes|nullable|date',
            'quantity_cap'         => 'sometimes|nullable|integer|min:0',
            'display_order'        => 'sometimes|integer|min:0',
            'is_active'            => 'sometimes|boolean',
        ]);

        $pricing->update($validated);

        return response()->json(['data' => $pricing->fresh()->load('tier')]);
    }

    public function destroy(Request $request, string $eventId, string $pricingId): JsonResponse
    {
        $event = $this->findEvent($request, $eventId);
        $pricing = $event->tierPricing()->where('id', $pricingId)->firstOrFail();

        // Refuse if there are issued tickets at this price.
        $sold = \App\Models\Ticket::where('event_id', $event->id)
            ->where('price_tier_id', $pricing->price_tier_id)
            ->whereIn('status', ['issued', 'scanned'])
            ->exists();

        if ($sold) {
            return response()->json([
                'error' => 'pricing_in_use',
                'message' => 'Cannot delete pricing with sold tickets. Deactivate it instead.',
            ], 409);
        }

        $pricing->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    private function findEvent(Request $request, string $eventId): Event
    {
        $membership = $request->attributes->get('organizer_membership');
        $event = Event::where('id', $eventId)
            ->where('organizer_id', $membership->organizer_id)
            ->first();
        if (! $event) abort(404, 'Event not found.');
        return $event;
    }
}