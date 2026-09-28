<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImagingStudyStoreRequest extends FormRequest
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
            'modality' => ['required', 'string', 'max:16', Rule::in([
                'CR', 'CT', 'DX', 'MG', 'MR', 'NM', 'OT', 'PT', 'RF', 'US', 'XA',
            ])],
            'study_description' => ['required', 'string', 'min:2', 'max:180'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
