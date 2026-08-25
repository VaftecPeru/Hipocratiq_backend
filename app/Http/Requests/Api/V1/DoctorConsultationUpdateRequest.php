<?php

namespace App\Http\Requests\Api\V1;

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
            'signature' => ['sometimes', 'nullable', 'array'],
            'signature.signed_by' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'signature.signed_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->has('sections')) {
                return;
            }

            $activeKeys = ClinicalHistorySection::query()->where('is_active', true)->pluck('key')->all();
            $providedKeys = array_keys($this->input('sections', []));

            $unknownKeys = array_diff($providedKeys, $activeKeys);
            if ($unknownKeys !== []) {
                $validator->errors()->add('sections', 'Sección desconocida: '.implode(', ', $unknownKeys).'.');
            }
        }];
    }
}
