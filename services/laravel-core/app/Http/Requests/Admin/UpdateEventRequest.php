<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $eventId = $this->route('id');

        return [
            'title' => 'sometimes|string|max:255',
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                'regex:/^[a-z0-9\-]+$/',
                Rule::unique('events', 'slug')->ignore($eventId),
            ],
            'short_description' => 'sometimes|nullable|string|max:500',
            'description' => 'sometimes|nullable|string|max:20000',
            'hero_image_url' => 'sometimes|nullable|url|max:500',
            'gallery' => 'sometimes|nullable|array|max:20',
            'gallery.*' => 'url|max:500',

            'venue_id' => 'sometimes|string|exists:venues,id',
            'seat_map_id' => 'sometimes|string|exists:seat_maps,id',

            'category' => ['sometimes', Rule::in([
                'music', 'sports', 'theater', 'comedy',
                'conference', 'festival', 'family', 'other',
            ])],
            'tags' => 'sometimes|nullable|array|max:20',
            'tags.*' => 'string|max:32',

            'performer_name' => 'sometimes|nullable|string|max:255',
            'performer_bio' => 'sometimes|nullable|string|max:5000',

            'age_restriction' => 'sometimes|integer|min:0|max:21',

            'sales_start_at' => 'sometimes|nullable|date',
            'sales_end_at' => 'sometimes|nullable|date|after:sales_start_at',

            'max_tickets_per_user' => 'sometimes|integer|min:1|max:20',
            'max_tickets_per_card' => 'sometimes|integer|min:1|max:20',

            'refund_cutoff_hours' => 'sometimes|integer|min:0|max:720',
            'allow_refunds_after_start' => 'sometimes|boolean',

            'settings' => 'sometimes|nullable|array',
        ];
    }
}