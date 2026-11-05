<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVenueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $venueId = $this->route('id');

        return [
            'name' => 'sometimes|string|max:255',
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                'regex:/^[a-z0-9\-]+$/',
                Rule::unique('venues', 'slug')->ignore($venueId),
            ],
            'description' => 'sometimes|nullable|string|max:5000',

            'address_line_1' => 'sometimes|nullable|string|max:255',
            'address_line_2' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:120',
            'state' => 'sometimes|nullable|string|max:120',
            'postal_code' => 'sometimes|nullable|string|max:32',
            'country' => 'sometimes|nullable|string|size:2',

            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',

            'phone' => 'sometimes|nullable|string|max:32',
            'email' => 'sometimes|nullable|email|max:255',
            'website' => 'sometimes|nullable|url|max:500',

            'timezone' => 'sometimes|nullable|string|max:64',
            'total_capacity' => 'sometimes|integer|min:0|max:500000',
            'settings' => 'sometimes|nullable|array',
        ];
    }
}