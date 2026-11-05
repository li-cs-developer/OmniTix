<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreSeatMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
            'width' => 'integer|min:100|max:20000',
            'height' => 'integer|min:100|max:20000',
            'meta' => 'nullable|array',
            'is_published' => 'boolean',
        ];
    }
}