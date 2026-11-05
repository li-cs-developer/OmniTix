<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\TicketAdminResource;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendeeController extends Controller
{
    /**
     * GET /api/v1/admin/events/{id}/attendees
     */
    public function index(Request $request, string $id): JsonResponse
    {
        $membership = $request->attributes->get('organizer_membership');

        $event = Event::query()
            ->where('id', $id)
            ->where('organizer_id', $membership->organizer_id)
            ->first();

        if (! $event) abort(404, 'Event not found.');

        $query = Ticket::query()
            ->where('event_id', $event->id)
            ->with(['seat', 'priceTier', 'order']);

        // Filters
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($occurrenceId = $request->query('event_occurrence_id')) {
            $query->where('event_occurrence_id', $occurrenceId);
        }
        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'ilike', "%{$search}%")
                  ->orWhereHas('order', fn ($q2) => $q2->where('guest_email', 'ilike', "%{$search}%"));
            });
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => TicketAdminResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}