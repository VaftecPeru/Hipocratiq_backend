<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ClinicStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminClinicUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'country_code' => ['sometimes', 'string', Rule::in(['MX', 'PE', 'US'])],
            'address' => ['sometimes', 'string', 'min:2', 'max:255'],
            'status' => ['sometimes', Rule::enum(ClinicStatus::class)],
            'plan' => ['sometimes', 'string', Rule::exists('plans', 'key')->where('is_active', true)],
        ];
    }
}
