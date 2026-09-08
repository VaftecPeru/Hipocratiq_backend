<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ClinicAppointmentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'doctor_user_id' => ['sometimes', 'required', 'integer'],
            'scheduled_at' => ['sometimes', 'required', 'date', 'after:now'],
            'appointment_type' => ['sometimes', 'required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
