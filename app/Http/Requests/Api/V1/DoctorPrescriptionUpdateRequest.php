<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PrescriptionStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DoctorPrescriptionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'medication' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            'dosage' => ['sometimes', 'nullable', 'string', 'max:120'],
            'frequency' => ['sometimes', 'nullable', 'string', 'max:120'],
            'duration_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'status' => ['sometimes', 'string', Rule::in(array_column(PrescriptionStatus::cases(), 'value'))],
            'issued_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:issued_at'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
