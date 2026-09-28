<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ImagingStudyStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImagingStudyStatusUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(array_column(ImagingStudyStatus::cases(), 'value'))],
            'study_instance_uid' => ['sometimes', 'nullable', 'string', 'max:128'],
            'pacs_provider' => ['sometimes', 'nullable', 'string', 'max:60'],
            'pacs_study_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'report_summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'study_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
