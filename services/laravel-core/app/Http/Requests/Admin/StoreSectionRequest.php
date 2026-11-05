<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:32|regex:/^[A-Z0-9_\-]+$/',
            'kind' => ['required', Rule::in(['seated', 'ga', 'box', 'table', 'accessible'])],
            'description' => 'nullable|string|max:1000',
            'visual' => 'nullable|array',
            'display_order' => 'integer|min:0|max:9999',
            'is_active' => 'boolean',
        ];
    }
}