<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSeatMapRequest;
use App\Http\Resources\Admin\SeatMapAdminResource;
use App\Models\SeatMap;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SeatMapController extends Controller
{
    public function index(Request $request, string $venueId): JsonResponse
    {
        $venue = $this->findVenue($request, $venueId);

        $maps = $venue->seatMaps()->orderByDesc('version')->get();

        return response()->json([
            'data' => SeatMapAdminResource::collection($maps),
        ]);
    }

    public function store(StoreSeatMapRequest $request, string $venueId): JsonResponse
    {
        $venue = $this->findVenue($request, $venueId);

        // Auto-increment version per venue
        $nextVersion = ($venue->seatMaps()->max('version') ?? 0) + 1;

        $seatMap = SeatMap::create([
            'venue_id' => $venue->id,
            'version' => $nextVersion,
            'name' => $request->input('name', "Version {$nextVersion}"),
            'notes' => $request->input('notes'),
            'width' => $request->input('width', 1000),
            'height' => $request->input('height', 800),
            'meta' => $request->input('meta', []),
            'is_published' => $request->boolean('is_published', false),
        ]);

        Log::info('Seat map created', ['seat_map_id' => $seatMap->id, 'venue_id' => $venue->id]);

        return response()->json([
            'data' => new SeatMapAdminResource($seatMap),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $seatMap = $this->findForOrganizer($request, $id, true);
        $seatMap->load(['seats', 'gaZones']);

        $data = (new SeatMapAdminResource($seatMap))->toArray($request);
        $data['seats'] = $seatMap->seats->map(fn ($s) => [
            'id' => $s->id,
            'section_code' => $s->section,
            'row' => $s->row_label,
            'number' => $s->seat_number,
            'label' => $s->display_label,
            'x' => (float) $s->x,
            'y' => (float) $s->y,
            'rotation' => (float) $s->rotation,
            'attributes' => $s->attributes ?? [],
        ])->values();

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $seatMap = $this->findForOrganizer($request, $id);

        $validated = $request->validate([
            'name' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string|max:5000',
            'width' => 'sometimes|integer|min:100|max:20000',
            'height' => 'sometimes|integer|min:100|max:20000',
            'meta' => 'sometimes|nullable|array',
            'is_published' => 'sometimes|boolean',
        ]);

        $seatMap->update($validated);

        return response()->json([
            'data' => new SeatMapAdminResource($seatMap->fresh()->load('seats', 'gaZones')),
        ]);
    }

    /**
     * POST /api/v1/admin/seat-maps/{id}/seats/bulk
     *
     * Bulk-creates seats from a JSON payload. Returns count created.
     * The payload shape:
     *   {
     *     "seats": [
     *       { "section_code": "ORCH", "row": "A", "number": "1",
     *         "label": "Orchestra A1", "x": 100, "y": 150, "rotation": 0,
     *         "attributes": {} },
     *       ...
     *     ]
     *   }
     */
    public function bulkCreateSeats(Request $request, string $id): JsonResponse
    {
        $seatMap = $this->findForOrganizer($request, $id);

        $validated = $request->validate([
            'seats' => 'required|array|min:1|max:50000',
            'seats.*.section_code' => 'required|string|max:32',
            'seats.*.row' => 'required|string|max:32',
            'seats.*.number' => 'required|string|max:16',
            'seats.*.label' => 'required|string|max:120',
            'seats.*.x' => 'required|numeric',
            'seats.*.y' => 'required|numeric',
            'seats.*.rotation' => 'nullable|numeric',
            'seats.*.attributes' => 'nullable|array',
        ]);

        // Resolve all section codes to venue_section IDs
        $sectionCodes = collect($validated['seats'])->pluck('section_code')->unique();
        $sections = $seatMap->venue->sections()
            ->whereIn('code', $sectionCodes->all())
            ->get()
            ->keyBy('code');

        $missing = $sectionCodes->diff($sections->keys());
        if ($missing->isNotEmpty()) {
            return response()->json([
                'error' => 'unknown_sections',
                'message' => 'Unknown section codes: ' . $missing->implode(', '),
            ], 422);
        }

        $now = now();
        $rows = [];

        foreach ($validated['seats'] as $seat) {
            $section = $sections[$seat['section_code']];
            $rows[] = [
                'id' => (string) Str::ulid(),
                'seat_map_id' => $seatMap->id,
                'venue_section_id' => $section->id,
                'section' => $seat['section_code'],
                'row_label' => $seat['row'],
                'seat_number' => $seat['number'],
                'display_label' => $seat['label'],
                'x' => $seat['x'],
                'y' => $seat['y'],
                'rotation' => $seat['rotation'] ?? 0,
                'attributes' => json_encode($seat['attributes'] ?? []),
                'meta' => json_encode([]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('seats')->insert($chunk);
            }
        });

        Log::info('Bulk seats created', ['seat_map_id' => $seatMap->id, 'count' => count($rows)]);

        return response()->json([
            'data' => [
                'created' => count($rows),
                'seat_map_id' => $seatMap->id,
            ],
        ], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $seatMap = $this->findForOrganizer($request, $id);

        if ($seatMap->events()->exists()) {
            return response()->json([
                'error' => 'seat_map_in_use',
                'message' => 'Cannot delete a seat map that has events.',
            ], 409);
        }

        $seatMap->delete();

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

    private function findForOrganizer(Request $request, string $id, bool $withTrashed = false): SeatMap
    {
        $membership = $request->attributes->get('organizer_membership');

        $query = SeatMap::query()
            ->where('id', $id)
            ->whereHas('venue', function ($q) use ($membership) {
                $q->where('organizer_id', $membership->organizer_id);
            });

        if ($withTrashed) {
            $query->withTrashed();
        }

        $seatMap = $query->first();
        if (! $seatMap) abort(404, 'Seat map not found.');

        return $seatMap;
    }
}