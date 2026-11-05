<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Seat;
use App\Models\GaZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SeatController extends Controller
{
    /**
     * GET /api/v1/events/{eventId}/seats
     *
     * Returns the seat map layout + seats + GA zones for the event.
     */
    public function layout(string $eventId): JsonResponse
    {
        $event = Event::with(['seatMap', 'venue.sections'])->find($eventId);

        if (! $event) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Event {$eventId} not found",
            ], 404);
        }

        $seatMap = $event->seatMap;
        $seats = Seat::where('seat_map_id', $seatMap->id)
            ->orderBy('section')
            ->orderBy('row_label')
            ->orderByRaw('CAST(seat_number AS INTEGER)')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'section_code' => $s->section,
                'row' => $s->row_label,
                'number' => $s->seat_number,
                'label' => $s->display_label,
                'x' => (float) $s->x,
                'y' => (float) $s->y,
                'rotation' => (float) $s->rotation,
                'attributes' => $s->attributes ?? [],
            ]);

        $gaZones = GaZone::where('seat_map_id', $seatMap->id)
            ->get()
            ->map(fn ($z) => [
                'id' => $z->id,
                'code' => $z->code,
                'name' => $z->name,
                'capacity' => $z->capacity,
                'visual' => $z->visual ?? [],
            ]);

        $sections = $event->venue->sections->map(fn ($s) => [
            'id' => $s->id,
            'code' => $s->code,
            'name' => $s->name,
            'kind' => $s->kind,
            'visual' => $s->visual ?? [],
        ]);

        return response()->json([
            'data' => [
                'seat_map_id' => $seatMap->id,
                'width' => $seatMap->width,
                'height' => $seatMap->height,
                'sections' => $sections,
                'seats' => $seats,
                'ga_zones' => $gaZones,
            ],
        ]);
    }

    /**
     * POST /api/v1/seats/hold
     *
     * Proxies to Go inventory service's HTTP gateway.
     */
    public function hold(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'occurrence_id' => 'required|string',
            'seat_id' => 'required|string',
            'user_id' => 'required|string',
        ]);

        $inventoryUrl = config('services.inventory.http_url');

        $response = Http::timeout(5)
            ->post("{$inventoryUrl}/api/v1/seats/hold", $validated);

        if ($response->failed()) {
            return response()->json([
                'error' => $response->json('error') ?? 'inventory_error',
                'message' => $response->json('message') ?? 'Hold failed',
            ], $response->status());
        }

        return response()->json($response->json(), $response->status());
    }

    /**
     * POST /api/v1/ga/hold
     *
     * Proxies to Go inventory service's HTTP gateway.
     */
    public function holdGa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'occurrence_id' => 'required|string',
            'ga_zone_id'    => 'required|string',
            'quantity'      => 'required|integer|min:1|max:20',
            'user_id'       => 'required|string',
        ]);

        $inventoryUrl = config('services.inventory.http_url');

        $response = Http::timeout(5)
            ->post("{$inventoryUrl}/api/v1/ga/hold", $validated);

        if ($response->failed()) {
            return response()->json([
                'error'   => $response->json('error') ?? 'inventory_error',
                'message' => $response->json('message') ?? 'GA hold failed',
            ], $response->status());
        }

        return response()->json($response->json(), $response->status());
    }

    /**
     * POST /api/v1/seats/release
     */
    public function release(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'user_id' => 'required|string',
        ]);

        $inventoryUrl = config('services.inventory.http_url');

        $response = Http::timeout(5)
            ->post("{$inventoryUrl}/api/v1/seats/release", $validated);

        return response()->json($response->json() ?? [], $response->status());
    }

    /**
     * GET /api/v1/seats/states?occurrence_id=X
     */
    public function states(Request $request): JsonResponse
    {
        $occurrenceId = $request->query('occurrence_id');
        if (! $occurrenceId) {
            return response()->json([
                'error' => 'missing_occurrence_id',
                'message' => 'occurrence_id query param required',
            ], 400);
        }

        $inventoryUrl = config('services.inventory.http_url');

        $response = Http::timeout(5)
            ->get("{$inventoryUrl}/api/v1/seats/states", [
                'occurrence_id' => $occurrenceId,
            ]);

        return response()->json($response->json() ?? [], $response->status());
    }
}