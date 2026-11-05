<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PromoCodeAdminResource;
use App\Models\Event;
use App\Models\PromoCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PromoCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $membership = $request->attributes->get('organizer_membership');

        $query = PromoCode::query()
            ->where('organizer_id', $membership->organizer_id);

        if ($eventId = $request->query('event_id')) {
            $query->where('event_id', $eventId);
        }
        if ($search = $request->query('q')) {
            $query->where('code', 'ilike', "%{$search}%");
        }
        if ($request->query('include_deleted') === 'true') {
            $query->withTrashed();
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => PromoCodeAdminResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $membership = $request->attributes->get('organizer_membership');

        $validated = $request->validate([
            'event_id' => [
                'nullable', 'string',
                Rule::exists('events', 'id')
                    ->where('organizer_id', $membership->organizer_id),
            ],
            'code' => [
                'required', 'string', 'max:64',
                'regex:/^[A-Z0-9_\-]+$/',
                Rule::unique('promo_codes', 'code')
                    ->where('organizer_id', $membership->organizer_id),
            ],
            'description' => 'nullable|string|max:500',

            'discount_type' => ['required', Rule::in(['percent', 'fixed', 'bogo'])],
            'discount_value' => 'required|integer|min:0|max:10000000',
            'max_discount_cents' => 'nullable|integer|min:0',

            'max_redemptions' => 'nullable|integer|min:1',
            'max_redemptions_per_user' => 'integer|min:1|max:1000',

            'applicable_sections' => 'nullable|array',
            'applicable_sections.*' => 'string|max:32',
            'applicable_tier_ids' => 'nullable|array',
            'applicable_tier_ids.*' => 'string',

            'min_order_cents' => 'integer|min:0',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at',
            'is_active' => 'boolean',
        ]);

        // Sanity: percent discount should be ≤ 10000 (100.00%)
        if ($validated['discount_type'] === 'percent' && $validated['discount_value'] > 10000) {
            return response()->json([
                'error' => 'invalid_discount',
                'message' => 'Percent discounts must be ≤ 10000 (representing 100%).',
            ], 422);
        }

        $promo = PromoCode::create([
            ...$validated,
            'organizer_id' => $membership->organizer_id,
        ]);

        return response()->json([
            'data' => new PromoCodeAdminResource($promo),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $promo = $this->findForOrganizer($request, $id, true);

        return response()->json([
            'data' => new PromoCodeAdminResource($promo),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $promo = $this->findForOrganizer($request, $id);
        $membership = $request->attributes->get('organizer_membership');

        $validated = $request->validate([
            'description' => 'sometimes|nullable|string|max:500',
            'discount_type' => ['sometimes', Rule::in(['percent', 'fixed', 'bogo'])],
            'discount_value' => 'sometimes|integer|min:0|max:10000000',
            'max_discount_cents' => 'sometimes|nullable|integer|min:0',
            'max_redemptions' => 'sometimes|nullable|integer|min:1',
            'max_redemptions_per_user' => 'sometimes|integer|min:1|max:1000',
            'applicable_sections' => 'sometimes|nullable|array',
            'applicable_tier_ids' => 'sometimes|nullable|array',
            'min_order_cents' => 'sometimes|integer|min:0',
            'starts_at' => 'sometimes|nullable|date',
            'expires_at' => 'sometimes|nullable|date',
            'is_active' => 'sometimes|boolean',
        ]);

        $promo->update($validated);

        return response()->json([
            'data' => new PromoCodeAdminResource($promo->fresh()),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $promo = $this->findForOrganizer($request, $id);

        // Never hard-delete: soft-delete is fine; redemptions stay for audit.
        $promo->update(['is_active' => false]);
        $promo->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    private function findForOrganizer(Request $request, string $id, bool $withTrashed = false): PromoCode
    {
        $membership = $request->attributes->get('organizer_membership');

        $query = PromoCode::query()
            ->where('id', $id)
            ->where('organizer_id', $membership->organizer_id);

        if ($withTrashed) $query->withTrashed();

        $promo = $query->first();
        if (! $promo) abort(404, 'Promo code not found.');

        return $promo;
    }
}