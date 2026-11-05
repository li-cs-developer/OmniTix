<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\PromoCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromoController extends Controller
{
    public function validateCode(Request $request, PromoCodeService $svc): JsonResponse
    {
        $validated = $request->validate([
            'code'           => 'required|string|max:64',
            'event_id'       => 'required|string',
            'user_id'        => 'nullable|string',
            'items'          => 'required|array|min:1',
            'items.*.seat_id'      => 'required|string',
            'items.*.price_tier_id'=> 'required|string',
            'items.*.price_cents'  => 'required|integer|min:0',
            'items.*.section_code' => 'nullable|string',
        ]);

        $event = Event::find($validated['event_id']);
        if (! $event) {
            return response()->json(['error' => 'event_not_found'], 404);
        }

        $result = $svc->validate(
            $validated['code'],
            $event->organizer_id,
            $event->id,
            $validated['items'],
            $validated['user_id'] ?? null,
        );

        return response()->json(['data' => $result]);
    }
}