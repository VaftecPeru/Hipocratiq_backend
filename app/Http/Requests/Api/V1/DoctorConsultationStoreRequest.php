<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ConsultationStatus;
use App\Models\ClinicalHistorySection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DoctorConsultationStoreRequest extends FormRequest
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
            'chief_complaint' => ['required', 'string', 'min:5', 'max:2000'],
            'objective' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(array_map(fn ($case) => $case->value, ConsultationStatus::cases()))],
            'sections' => ['nullable', 'array'],
            'sections.*' => ['array'],
            'signature' => ['nullable', 'array'],
            'signature.signed_by' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'signature.signed_at' => ['nullable', 'date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $activeKeys = ClinicalHistorySection::query()->where('is_active', true)->pluck('key')->all();
            $providedKeys = array_keys($this->input('sections', []));

            $unknownKeys = array_diff($providedKeys, $activeKeys);
            if ($unknownKeys !== []) {
                $validator->errors()->add('sections', 'Sección desconocida: '.implode(', ', $unknownKeys).'.');
            }
        }];
    }
}
