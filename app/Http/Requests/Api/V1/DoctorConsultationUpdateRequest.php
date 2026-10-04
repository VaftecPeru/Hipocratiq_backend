<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ClinicalCodingCategory;
use App\Enums\ConsultationStatus;
use App\Models\ClinicalHistorySection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DoctorConsultationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'appointment_id' => ['nullable', 'integer', Rule::exists('appointments', 'id')],
            'chief_complaint' => ['sometimes', 'string', 'min:5', 'max:2000'],
            'objective' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(array_map(fn ($case) => $case->value, ConsultationStatus::cases()))],
            'sections' => ['sometimes', 'nullable', 'array'],
            'sections.*' => ['array'],
            'codings' => ['sometimes', 'array', 'max:100'],
            'codings.*.category' => ['required', Rule::enum(ClinicalCodingCategory::class)],
            'codings.*.system' => ['required', 'string', 'max:255'],
            'codings.*.code' => ['required', 'string', 'max:120'],
            'codings.*.display' => ['required', 'string', 'max:255'],
            'codings.*.status' => ['nullable', 'string', 'max:40'],
            'signature' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->has('sections')) {
                return;
            }

            $activeKeys = ClinicalHistorySection::query()->where('is_active', true)->pluck('key')->all();
            $sections = $this->input('sections', []);
            if (! is_array($sections)) {
                return;
            }

            $providedKeys = array_keys($sections);

            $unknownKeys = array_diff($providedKeys, $activeKeys);
            if ($unknownKeys !== []) {
                $validator->errors()->add('sections', 'Sección desconocida: '.implode(', ', $unknownKeys).'.');
            }
        }];
    }
}
