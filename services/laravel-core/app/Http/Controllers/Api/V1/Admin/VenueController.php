<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreVenueRequest;
use App\Http\Requests\Admin\UpdateVenueRequest;
use App\Http\Resources\Admin\VenueAdminResource;
use App\Models\Venue;
use App\Models\VenueSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $membership = $request->attributes->get('organizer_membership');

        $query = Venue::query()
            ->where('organizer_id', $membership->organizer_id)
            ->with('sections')
            ->withCount('seatMaps');

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('city', 'ilike', "%{$search}%");
            });
        }
        if ($request->query('include_deleted') === 'true') {
            $query->withTrashed();
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => VenueAdminResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(StoreVenueRequest $request): JsonResponse
    {
        $membership = $request->attributes->get('organizer_membership');

        $venue = Venue::create([
            ...$request->validated(),
            'organizer_id' => $membership->organizer_id,
        ]);

        Log::info('Venue created', ['venue_id' => $venue->id, 'user_id' => $request->user()->id]);

        return response()->json([
            'data' => new VenueAdminResource($venue->load('sections')),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $venue = $this->findForOrganizer($request, $id, true);

        return response()->json([
            'data' => new VenueAdminResource($venue->load(['sections', 'seatMaps'])),
        ]);
    }

    public function update(UpdateVenueRequest $request, string $id): JsonResponse
    {
        $venue = $this->findForOrganizer($request, $id);
        $venue->update($request->validated());

        Log::info('Venue updated', ['venue_id' => $venue->id]);

        return response()->json([
            'data' => new VenueAdminResource($venue->fresh()->load('sections')),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $venue = $this->findForOrganizer($request, $id);

        // Refuse if any events reference this venue
        if ($venue->events()->exists()) {
            return response()->json([
                'error' => 'venue_in_use',
                'message' => 'Cannot delete a venue that has events.',
            ], 409);
        }

        $venue->delete();

        Log::warning('Venue soft-deleted', ['venue_id' => $venue->id]);

        return response()->json(['data' => ['ok' => true]]);
    }

    private function findForOrganizer(Request $request, string $id, bool $withTrashed = false): Venue
    {
        $membership = $request->attributes->get('organizer_membership');

        $query = Venue::query()
            ->where('id', $id)
            ->where('organizer_id', $membership->organizer_id);

        if ($withTrashed) {
            $query->withTrashed();
        }

        $venue = $query->first();
        if (! $venue) abort(404, 'Venue not found.');

        return $venue;
    }
}