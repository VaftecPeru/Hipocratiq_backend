<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipPlanStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9\-]+$/', Rule::unique('plans', 'key')],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'monthly_price_usd' => ['required', 'numeric', 'min:0'],
            'annual_price_usd' => ['required', 'numeric', 'min:0'],
            'features' => ['required', 'array', 'min:1'],
            'features.*' => ['string', 'max:255'],
            'publicly_selectable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
