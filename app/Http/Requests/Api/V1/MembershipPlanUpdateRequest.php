<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipPlanUpdateRequest extends FormRequest
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
            'key' => ['sometimes', 'string', 'max:60', 'regex:/^[a-z][a-z0-9\-_]*$/', Rule::unique('plans', 'key')->ignore($this->route('plan'))],
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'monthly_price_usd' => ['sometimes', 'numeric', 'min:0'],
            'annual_price_usd' => ['sometimes', 'numeric', 'min:0'],
            'features' => ['sometimes', 'array', 'min:1'],
            'features.*' => ['string', 'max:255'],
            'publicly_selectable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
