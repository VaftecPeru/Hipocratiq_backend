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
            'report_summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'study_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
