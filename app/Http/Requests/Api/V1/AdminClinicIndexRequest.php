<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ClinicStatus;
use App\Enums\PlanKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminClinicIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'country_code' => ['sometimes', Rule::in(['MX', 'PE', 'US'])],
            'plan' => ['sometimes', Rule::enum(PlanKey::class)],
            'status' => ['sometimes', Rule::enum(ClinicStatus::class)],
        ];
    }
}
