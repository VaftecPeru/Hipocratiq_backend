<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\LaboratoryOrderStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LaboratoryOrderStatusUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(array_column(LaboratoryOrderStatus::cases(), 'value'))],
            'is_critical' => ['sometimes', 'boolean'],
            'result_summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'result_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
