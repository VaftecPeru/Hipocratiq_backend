<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Specialty;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ClinicPatientStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['nullable', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
            'phone_number' => ['required', 'string', 'max:32'],
            'country_code' => ['required', 'string', Rule::in(['MX', 'PE', 'US'])],
            'document_type' => ['nullable', 'string', 'max:40'],
            'document_number' => ['nullable', 'string', 'max:80'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'sex' => ['nullable', 'string', Rule::in(['Femenino', 'Masculino', 'Otro'])],
            'address' => ['nullable', 'string', 'max:240'],
            'emergency_contact' => ['nullable', 'string', 'max:160'],
            'requested_doctor_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'specialty_id' => ['nullable', 'integer', Rule::exists('specialties', 'id')->where('is_active', true)->whereNull('parent_id')],
            'subspecialty_id' => ['nullable', 'integer', Rule::exists('specialties', 'id')->where('is_active', true)],
            'reason_category' => ['nullable', 'string', 'max:80'],
            'objective' => ['nullable', 'string', 'min:10', 'max:2000'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'temperature_c' => ['nullable', 'numeric', 'between:30,45'],
            'systolic_bp' => ['nullable', 'integer', 'between:60,250'],
            'diastolic_bp' => ['nullable', 'integer', 'between:30,160'],
            'heart_rate' => ['nullable', 'integer', 'between:30,220'],
            'respiratory_rate' => ['nullable', 'integer', 'between:5,60'],
            'oxygen_saturation' => ['nullable', 'integer', 'between:70,100'],
            'triage_priority' => ['nullable', 'string', Rule::in(['routine', 'urgent', 'emergency'])],
            'triage_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $hasIntakeData = collect(['requested_doctor_user_id', 'specialty_id', 'subspecialty_id', 'reason_category', 'objective', 'observations', 'temperature_c', 'systolic_bp', 'diastolic_bp', 'heart_rate', 'respiratory_rate', 'oxygen_saturation', 'triage_priority', 'triage_notes'])
                ->contains(fn (string $field): bool => $this->has($field));
            $specialtyId = $this->integer('specialty_id');
            $subspecialtyId = $this->integer('subspecialty_id');
            $systolicBp = $this->input('systolic_bp');
            $diastolicBp = $this->input('diastolic_bp');

            if ($hasIntakeData && trim((string) $this->input('objective')) === '') {
                $validator->errors()->add('objective', 'El objetivo del paciente es obligatorio para la admisión clínica.');
            }

            if ($systolicBp !== null && $diastolicBp !== null && (int) $diastolicBp >= (int) $systolicBp) {
                $validator->errors()->add('diastolic_bp', 'La presión diastólica debe ser menor que la sistólica.');
            }

            if ($subspecialtyId !== 0 && $specialtyId === 0) {
                $validator->errors()->add('specialty_id', 'Selecciona la especialidad principal de la subespecialidad.');
            }

            if ($subspecialtyId !== 0 && $specialtyId !== 0 && ! Specialty::query()
                ->whereKey($subspecialtyId)
                ->where('parent_id', $specialtyId)
                ->where('is_active', true)
                ->exists()) {
                $validator->errors()->add('subspecialty_id', 'La subespecialidad no pertenece a la especialidad seleccionada.');
            }
        }];
    }
}
