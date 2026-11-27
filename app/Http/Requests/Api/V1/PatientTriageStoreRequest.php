<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PatientTriageStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'temperature_c' => ['nullable', 'numeric', 'between:30,45'],
            'systolic_bp' => ['nullable', 'integer', 'between:60,250'],
            'diastolic_bp' => ['nullable', 'integer', 'between:30,160'],
            'heart_rate' => ['nullable', 'integer', 'between:30,220'],
            'respiratory_rate' => ['nullable', 'integer', 'between:5,60'],
            'oxygen_saturation' => ['nullable', 'integer', 'between:70,100'],
            'triage_priority' => ['prohibited'],
            'triage_level' => ['required', 'string', Rule::in(['I', 'II', 'III', 'IV', 'V'])],
            'triage_status' => ['required', 'string', Rule::in(['draft', 'submitted'])],
            'triage_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'temperature_c.numeric' => 'La temperatura debe ser un valor numérico.',
            'temperature_c.between' => 'Verifica la temperatura: el rango técnico aceptado es 30–45 °C.',
            'systolic_bp.integer' => 'La presión sistólica debe ser un número entero.',
            'systolic_bp.between' => 'Verifica la presión sistólica: el rango técnico aceptado es 60–250 mmHg.',
            'diastolic_bp.integer' => 'La presión diastólica debe ser un número entero.',
            'diastolic_bp.between' => 'Verifica la presión diastólica: el rango técnico aceptado es 30–160 mmHg.',
            'heart_rate.integer' => 'La frecuencia cardiaca debe ser un número entero.',
            'heart_rate.between' => 'Verifica la frecuencia cardiaca: el rango técnico aceptado es 30–220 lpm.',
            'respiratory_rate.integer' => 'La frecuencia respiratoria debe ser un número entero.',
            'respiratory_rate.between' => 'Verifica la frecuencia respiratoria: el rango técnico aceptado es 5–60 rpm.',
            'oxygen_saturation.integer' => 'La saturación de oxígeno debe ser un número entero.',
            'oxygen_saturation.between' => 'Verifica la saturación de oxígeno: el rango técnico aceptado es 70–100%.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'triage_notes'] as $field) {
            if ($this->has($field) && $this->input($field) !== null) {
                $this->merge([$field => trim((string) $this->input($field))]);
            }
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $systolic = $this->input('systolic_bp');
            $diastolic = $this->input('diastolic_bp');

            if ($systolic !== null && $diastolic !== null && (int) $diastolic >= (int) $systolic) {
                $validator->errors()->add('diastolic_bp', 'La presión diastólica debe ser menor que la sistólica.');
            }
        }];
    }
}
