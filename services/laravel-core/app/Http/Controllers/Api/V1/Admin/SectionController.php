<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSectionRequest;
use App\Models\Venue;
use App\Models\VenueSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SectionController extends Controller
{
    public function index(Request $request, string $venueId): JsonResponse
    {
        $venue = $this->findVenue($request, $venueId);

        return response()->json([
            'data' => $venue->sections()->orderBy('display_order')->get()->map(fn ($s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'kind' => $s->kind,
                'visual' => $s->visual ?? [],
                'display_order' => $s->display_order,
                'is_active' => (bool) $s->is_active,
            ])->values(),
        ]);
    }

    public function store(StoreSectionRequest $request, string $venueId): JsonResponse
    {
        $venue = $this->findVenue($request, $venueId);

        // Ensure code is unique within the venue
        if ($venue->sections()->where('code', $request->input('code'))->exists()) {
            return response()->json([
                'error' => 'duplicate_section_code',
                'message' => "Section code '{$request->input('code')}' already exists in this venue.",
            ], 422);
        }

        $section = $venue->sections()->create([
            ...$request->validated(),
            'venue_id' => $venue->id,
        ]);

        return response()->json([
            'data' => [
                'id' => $section->id,
                'code' => $section->code,
                'name' => $section->name,
                'kind' => $section->kind,
                'visual' => $section->visual ?? [],
                'display_order' => $section->display_order,
                'is_active' => (bool) $section->is_active,
            ],
        ], 201);
    }

    public function update(Request $request, string $venueId, string $sectionId): JsonResponse
    {
        $venue = $this->findVenue($request, $venueId);
        $section = $venue->sections()->where('id', $sectionId)->first();

        if (! $section) abort(404, 'Section not found.');

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:32|regex:/^[A-Z0-9_\-]+$/',
            'kind' => ['sometimes', Rule::in(['seated', 'ga', 'box', 'table', 'accessible'])],
            'description' => 'sometimes|nullable|string|max:1000',
            'visual' => 'sometimes|nullable|array',
            'display_order' => 'sometimes|integer|min:0|max:9999',
            'is_active' => 'sometimes|boolean',
        ]);

        $section->update($validated);

        return response()->json([
            'data' => [
                'id' => $section->id,
                'code' => $section->code,
                'name' => $section->name,
                'kind' => $section->kind,
                'visual' => $section->visual ?? [],
                'display_order' => $section->display_order,
                'is_active' => (bool) $section->is_active,
            ],
        ]);
    }

    public function destroy(Request $request, string $venueId, string $sectionId): JsonResponse
    {
        $venue = $this->findVenue($request, $venueId);
        $section = $venue->sections()->where('id', $sectionId)->first();

        if (! $section) abort(404, 'Section not found.');

        // Refuse if seats exist under this section
        if ($section->seats()->exists()) {
            return response()->json([
                'error' => 'section_in_use',
                'message' => 'Cannot delete a section that has seats.',
            ], 409);
        }

        $section->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    private function findVenue(Request $request, string $id): Venue
    {
        $membership = $request->attributes->get('organizer_membership');

        $venue = Venue::query()
            ->where('id', $id)
            ->where('organizer_id', $membership->organizer_id)
            ->first();

        if (! $venue) abort(404, 'Venue not found.');

        return $venue;
    }
}