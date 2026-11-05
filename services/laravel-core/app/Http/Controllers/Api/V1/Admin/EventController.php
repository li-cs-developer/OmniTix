<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEventRequest;
use App\Http\Requests\Admin\UpdateEventRequest;
use App\Http\Resources\Admin\EventAdminResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EventController extends Controller
{
    /**
     * GET /api/v1/admin/events
     *
     * Lists events for the current organizer.
     */
    public function index(Request $request): JsonResponse
    {
        $membership = $request->attributes->get('organizer_membership');
        $organizerId = $membership->organizer_id;

        $query = Event::query()
            ->where('organizer_id', $organizerId)
            ->with(['venue:id,name,city', 'seatMap:id,version,name'])
            ->withCount('occurrences');

        // Filters
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('slug', 'ilike', "%{$search}%");
            });
        }
        if ($request->query('include_deleted') === 'true') {
            $query->withTrashed();
        }

        $page = (int) $request->query('page', 1);
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $paginator = $query->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => EventAdminResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/events
     */
    public function store(StoreEventRequest $request): JsonResponse
    {
        $membership = $request->attributes->get('organizer_membership');

        $event = Event::create([
            ...$request->validated(),
            'organizer_id' => $membership->organizer_id,
            'status' => 'draft',
        ]);

        Log::info('Event created', [
            'event_id' => $event->id,
            'organizer_id' => $membership->organizer_id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'data' => new EventAdminResource($event->load('venue', 'seatMap')),
        ], 201);
    }

    /**
     * GET /api/v1/admin/events/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $event = $this->findForOrganizer($request, $id, withTrashed: true);

        return response()->json([
            'data' => new EventAdminResource(
                $event->load(['venue', 'seatMap', 'occurrences']),
            ),
        ]);
    }

    /**
     * PATCH /api/v1/admin/events/{id}
     */
    public function update(UpdateEventRequest $request, string $id): JsonResponse
    {
        $event = $this->findForOrganizer($request, $id);

        if ($event->status === 'completed') {
            return response()->json([
                'error' => 'event_completed',
                'message' => 'Cannot update a completed event.',
            ], 409);
        }

        $event->update($request->validated());

        Log::info('Event updated', [
            'event_id' => $event->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'data' => new EventAdminResource($event->fresh()->load('venue', 'seatMap')),
        ]);
    }

    /**
     * DELETE /api/v1/admin/events/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $event = $this->findForOrganizer($request, $id);

        // Only draft events can be soft-deleted. Published events should be
        // cancelled (which triggers refunds), not deleted.
        if ($event->status !== 'draft') {
            return response()->json([
                'error' => 'event_not_deletable',
                'message' => 'Only draft events can be deleted. Cancel a live event instead.',
            ], 409);
        }

        $event->delete();

        Log::warning('Event soft-deleted', [
            'event_id' => $event->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * POST /api/v1/admin/events/{id}/publish
     */
    public function publish(Request $request, string $id): JsonResponse
    {
        $event = $this->findForOrganizer($request, $id);

        if (! in_array($event->status, ['draft', 'published'], true)) {
            return response()->json([
                'error' => 'invalid_transition',
                'message' => "Cannot publish from status '{$event->status}'.",
            ], 409);
        }

        // Sanity checks: must have at least one occurrence with a future date
        $futureOccurrences = $event->occurrences()
            ->where('starts_at', '>=', now())
            ->count();

        if ($futureOccurrences === 0) {
            return response()->json([
                'error' => 'no_upcoming_occurrences',
                'message' => 'Cannot publish an event without any future occurrences.',
            ], 422);
        }

        $event->update(['status' => 'on_sale']);

        Log::info('Event published', [
            'event_id' => $event->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'data' => new EventAdminResource($event->fresh()->load('venue', 'seatMap')),
        ]);
    }

    /**
     * POST /api/v1/admin/events/{id}/cancel
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $event = $this->findForOrganizer($request, $id);

        if ($event->status === 'cancelled') {
            return response()->json([
                'data' => new EventAdminResource($event),
            ]); // idempotent
        }

        $event->update(['status' => 'cancelled']);

        Log::warning('Event cancelled', [
            'event_id' => $event->id,
            'user_id' => $request->user()->id,
        ]);

        // NOTE: Refund processing for cancelled events is handled by a
        // separate workflow (Phase 8.6). For now we just mark the event.

        return response()->json([
            'data' => new EventAdminResource($event->fresh()->load('venue', 'seatMap')),
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────

    private function findForOrganizer(
        Request $request,
        string $id,
        bool $withTrashed = false,
    ): Event {
        $membership = $request->attributes->get('organizer_membership');

        $query = Event::query()
            ->where('id', $id)
            ->where('organizer_id', $membership->organizer_id);

        if ($withTrashed) {
            $query->withTrashed();
        }

        $event = $query->first();

        if (! $event) {
            abort(404, 'Event not found.');
        }

        return $event;
    }
}