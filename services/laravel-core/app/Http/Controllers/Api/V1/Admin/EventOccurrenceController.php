<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventOccurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventOccurrenceController extends Controller
{
    public function index(Request $request, string $eventId): JsonResponse
    {
        $event = $this->findEvent($request, $eventId);

        return response()->json([
            'data' => $event->occurrences()
                ->orderBy('starts_at')
                ->get()
                ->map(fn ($o) => $this->resource($o))
                ->values(),
        ]);
    }

    public function store(Request $request, string $eventId): JsonResponse
    {
        $event = $this->findEvent($request, $eventId);

        $validated = $request->validate([
            'starts_at'      => 'required|date|after:now',
            'ends_at'        => 'nullable|date|after:starts_at',
            'doors_open_at'  => 'nullable|date|before:starts_at',
            'label'          => 'nullable|string|max:128',
            'capacity_total' => 'integer|min:0',
            'status'         => ['sometimes', Rule::in([
                'scheduled','on_sale','sold_out','postponed','cancelled','completed',
            ])],
            'settings'       => 'nullable|array',
        ]);

        // Unique per event + starts_at (enforced by DB, but give a nice error).
        $exists = $event->occurrences()
            ->where('starts_at', $validated['starts_at'])
            ->exists();
        if ($exists) {
            return response()->json([
                'error' => 'duplicate_occurrence',
                'message' => 'An occurrence with this start time already exists.',
            ], 422);
        }

        $occurrence = $event->occurrences()->create([
            'capacity_total' => $validated['capacity_total'] ?? $event->seatMap->seats()->count(),
            ...$validated,
        ]);

        return response()->json(['data' => $this->resource($occurrence)], 201);
    }

    public function update(Request $request, string $eventId, string $occurrenceId): JsonResponse
    {
        $event = $this->findEvent($request, $eventId);
        $occurrence = $event->occurrences()->where('id', $occurrenceId)->firstOrFail();

        $validated = $request->validate([
            'starts_at'      => 'sometimes|date',
            'ends_at'        => 'sometimes|nullable|date',
            'doors_open_at'  => 'sometimes|nullable|date',
            'label'          => 'sometimes|nullable|string|max:128',
            'capacity_total' => 'sometimes|integer|min:0',
            'status'         => ['sometimes', Rule::in([
                'scheduled','on_sale','sold_out','postponed','cancelled','completed',
            ])],
            'settings'       => 'sometimes|nullable|array',
        ]);

        $occurrence->update($validated);

        return response()->json(['data' => $this->resource($occurrence->fresh())]);
    }

    public function destroy(Request $request, string $eventId, string $occurrenceId): JsonResponse
    {
        $event = $this->findEvent($request, $eventId);
        $occurrence = $event->occurrences()->where('id', $occurrenceId)->firstOrFail();

        // Guard: only draft occurrences can be deleted; live ones cancel.
        if ($occurrence->capacity_sold > 0) {
            return response()->json([
                'error' => 'occurrence_has_sales',
                'message' => 'Cannot delete an occurrence with sold tickets. Cancel it instead.',
            ], 409);
        }

        $occurrence->delete(); // soft delete

        return response()->json(['data' => ['ok' => true]]);
    }

    private function resource(EventOccurrence $o): array
    {
        return [
            'id'             => $o->id,
            'event_id'       => $o->event_id,
            'starts_at'      => $o->starts_at?->toIso8601String(),
            'ends_at'        => $o->ends_at?->toIso8601String(),
            'doors_open_at'  => $o->doors_open_at?->toIso8601String(),
            'label'          => $o->label,
            'capacity_total' => $o->capacity_total,
            'capacity_sold'  => $o->capacity_sold,
            'capacity_held'  => $o->capacity_held,
            'status'         => $o->status,
            'settings'       => $o->settings ?? [],
            'created_at'     => $o->created_at?->toIso8601String(),
        ];
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