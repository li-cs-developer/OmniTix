<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PriceTierAdminResource;
use App\Models\PriceTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PriceTierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $membership = $request->attributes->get('organizer_membership');

        $query = PriceTier::query()
            ->where('organizer_id', $membership->organizer_id);

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%");
            });
        }
        if ($request->query('include_deleted') === 'true') {
            $query->withTrashed();
        }

        $tiers = $query->orderBy('display_order')->get();

        return response()->json([
            'data' => PriceTierAdminResource::collection($tiers),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $membership = $request->attributes->get('organizer_membership');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => [
                'required', 'string', 'max:32',
                'regex:/^[A-Z0-9_\-]+$/',
                Rule::unique('price_tiers', 'code')
                    ->where('organizer_id', $membership->organizer_id),
            ],
            'description' => 'nullable|string|max:1000',
            'min_age' => 'integer|min:0|max:150',
            'max_age' => 'integer|min:0|max:150',
            'color' => 'string|max:7|regex:/^#[0-9a-fA-F]{6}$/',
            'display_order' => 'integer|min:0|max:9999',
            'is_active' => 'boolean',
        ]);

        $tier = PriceTier::create([
            ...$validated,
            'organizer_id' => $membership->organizer_id,
        ]);

        return response()->json([
            'data' => new PriceTierAdminResource($tier),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tier = $this->findForOrganizer($request, $id, true);

        return response()->json([
            'data' => new PriceTierAdminResource($tier),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tier = $this->findForOrganizer($request, $id);
        $membership = $request->attributes->get('organizer_membership');

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => [
                'sometimes', 'string', 'max:32',
                'regex:/^[A-Z0-9_\-]+$/',
                Rule::unique('price_tiers', 'code')
                    ->ignore($id)
                    ->where('organizer_id', $membership->organizer_id),
            ],
            'description' => 'sometimes|nullable|string|max:1000',
            'min_age' => 'sometimes|integer|min:0|max:150',
            'max_age' => 'sometimes|integer|min:0|max:150',
            'color' => 'sometimes|string|max:7|regex:/^#[0-9a-fA-F]{6}$/',
            'display_order' => 'sometimes|integer|min:0|max:9999',
            'is_active' => 'sometimes|boolean',
        ]);

        $tier->update($validated);

        return response()->json([
            'data' => new PriceTierAdminResource($tier->fresh()),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tier = $this->findForOrganizer($request, $id);

        // Refuse if any event_tier_pricing rows reference this tier
        if (\DB::table('event_tier_pricing')->where('price_tier_id', $id)->exists()) {
            return response()->json([
                'error' => 'tier_in_use',
                'message' => 'Cannot delete a tier that is used by events. Deactivate it instead.',
            ], 409);
        }

        $tier->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    private function findForOrganizer(Request $request, string $id, bool $withTrashed = false): PriceTier
    {
        $membership = $request->attributes->get('organizer_membership');

        $query = PriceTier::query()
            ->where('id', $id)
            ->where('organizer_id', $membership->organizer_id);

        if ($withTrashed) $query->withTrashed();

        $tier = $query->first();
        if (! $tier) abort(404, 'Price tier not found.');

        return $tier;
    }
}