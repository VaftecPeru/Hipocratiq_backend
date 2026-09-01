<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ClinicAppointmentStoreRequest extends FormRequest
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
            'doctor_user_id' => ['required', 'integer'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'appointment_type' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
