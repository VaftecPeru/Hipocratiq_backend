<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DoctorAppointmentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'scheduled_at' => ['sometimes', 'required', 'date', 'after:now'],
            'appointment_type' => ['sometimes', 'required', 'string', 'max:80'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
