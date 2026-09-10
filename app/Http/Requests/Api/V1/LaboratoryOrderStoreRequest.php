<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LaboratoryOrderStoreRequest extends FormRequest
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
            'study_name' => ['required', 'string', 'min:2', 'max:180'],
            'priority' => ['nullable', 'string', Rule::in(['normal', 'urgent'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
