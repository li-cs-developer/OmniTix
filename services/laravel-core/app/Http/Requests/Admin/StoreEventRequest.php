<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permission check is handled by the `perm:event.create` middleware.
        // This authorize() only needs to return true.
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9\-]+$/',
                Rule::unique('events', 'slug'),
            ],
            'short_description' => 'nullable|string|max:500',
            'description' => 'nullable|string|max:20000',
            'hero_image_url' => 'nullable|url|max:500',
            'gallery' => 'nullable|array|max:20',
            'gallery.*' => 'url|max:500',

            'venue_id' => 'required|string|exists:venues,id',
            'seat_map_id' => 'required|string|exists:seat_maps,id',

            'category' => ['required', Rule::in([
                'music', 'sports', 'theater', 'comedy',
                'conference', 'festival', 'family', 'other',
            ])],
            'tags' => 'nullable|array|max:20',
            'tags.*' => 'string|max:32',

            'performer_name' => 'nullable|string|max:255',
            'performer_bio' => 'nullable|string|max:5000',

            'age_restriction' => 'integer|min:0|max:21',

            'sales_start_at' => 'nullable|date',
            'sales_end_at' => 'nullable|date|after:sales_start_at',

            'max_tickets_per_user' => 'integer|min:1|max:20',
            'max_tickets_per_card' => 'integer|min:1|max:20',

            'refund_cutoff_hours' => 'integer|min:0|max:720',
            'allow_refunds_after_start' => 'boolean',

            'settings' => 'nullable|array',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Auto-slug from title if not provided
        if (! $this->has('slug') && $this->has('title')) {
            $this->merge([
                'slug' => \Illuminate\Support\Str::slug($this->input('title')),
            ]);
        }
    }
}