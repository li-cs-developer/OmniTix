<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVenueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9\-]+$/',
                Rule::unique('venues', 'slug'),
            ],
            'description' => 'nullable|string|max:5000',

            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:120',
            'state' => 'nullable|string|max:120',
            'postal_code' => 'nullable|string|max:32',
            'country' => 'nullable|string|size:2',

            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',

            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:500',

            'timezone' => 'nullable|string|max:64',
            'total_capacity' => 'integer|min:0|max:500000',
            'settings' => 'nullable|array',
        ];
    }
}