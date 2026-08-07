<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUserIndexRequest extends FormRequest
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
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'country_code' => ['sometimes', Rule::in(['MX', 'PE', 'US'])],
            'clinic_id' => ['sometimes', 'integer', Rule::exists('clinics', 'id')],
            'role' => ['sometimes', Rule::in(['super_admin', 'clinic_admin', 'doctor', 'patient', 'unassigned'])],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
        ];
    }
}
