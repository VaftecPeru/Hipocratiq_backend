<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PrescriptionStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DoctorPrescriptionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'patient_profile_id' => ['required', 'integer', 'exists:patient_profiles,id'],
            'consultation_id' => ['nullable', 'integer', 'exists:consultations,id'],
            'medication' => ['required', 'string', 'min:2', 'max:180'],
            'dosage' => ['nullable', 'string', 'max:120'],
            'frequency' => ['nullable', 'string', 'max:120'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'status' => ['nullable', 'string', Rule::in([PrescriptionStatus::Draft->value, PrescriptionStatus::Active->value])],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
