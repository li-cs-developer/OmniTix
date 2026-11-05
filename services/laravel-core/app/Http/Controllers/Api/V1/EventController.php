<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventDetailResource;
use App\Http\Resources\EventSummaryResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = Event::query()
            ->whereIn('status', ['published', 'on_sale', 'sold_out'])
            ->with([
                'venue:id,name,city,state',
                'occurrences' => function ($q) {
                    $q->where('starts_at', '>=', now())
                      ->orderBy('starts_at')
                      ->limit(1);
                },
                'tierPricing',
            ])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => EventSummaryResource::collection($events),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->with([
                'venue',
                'seatMap.gaZones',
                'venue.sections',
                'occurrences' => function ($q) {
                    $q->orderBy('starts_at');
                },
                'tierPricing.tier',
            ])
            ->first();

        if (! $event) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Event '{$slug}' not found",
            ], 404);
        }

        return response()->json([
            'data' => new EventDetailResource($event),
        ]);
    }
}