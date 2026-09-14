<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PatientIntakeDraftStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'declaration_accepted' => ['required', 'accepted'],
            'patient_profile_id' => ['prohibited'],
            'clinic_id' => ['prohibited'],
            'id' => ['prohibited'],
            'declaration_accepted_at' => ['prohibited'],
            'sections' => ['required', 'array:patient_identification,family_history,non_pathological_history,pathological_history,gyneco_obstetric_history,present_illness,systems_review,vital_signs'],
            'sections.patient_identification' => ['sometimes', 'array:name,age,document_type,document_number,birth_date,gender,occupation,address,guardian_name,relationship,phone_number,email,emergency_contact,insurance'],
            'sections.patient_identification.name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'sections.patient_identification.age' => ['sometimes', 'nullable', 'integer', 'between:0,130'],
            'sections.patient_identification.document_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'sections.patient_identification.document_number' => ['sometimes', 'nullable', 'string', 'max:80'],
            'sections.patient_identification.birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'sections.patient_identification.gender' => ['sometimes', 'nullable', 'string', Rule::in(['Femenino', 'Masculino', 'Otro'])],
            'sections.patient_identification.occupation' => ['sometimes', 'nullable', 'string', 'max:160'],
            'sections.patient_identification.address' => ['sometimes', 'nullable', 'string', 'max:240'],
            'sections.patient_identification.guardian_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'sections.patient_identification.relationship' => ['sometimes', 'nullable', 'string', 'max:80'],
            'sections.patient_identification.phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'sections.patient_identification.email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'sections.patient_identification.emergency_contact' => ['sometimes', 'nullable', 'string', 'max:160'],
            'sections.patient_identification.insurance' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sections.family_history' => ['sometimes', 'array:description'],
            'sections.family_history.description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'sections.non_pathological_history' => ['sometimes', 'array:description'],
            'sections.non_pathological_history.description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'sections.pathological_history' => ['sometimes', 'array:description'],
            'sections.pathological_history.description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'sections.gyneco_obstetric_history' => ['sometimes', 'array:description'],
            'sections.gyneco_obstetric_history.description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'sections.present_illness' => ['sometimes', 'array:reason,illness_narrative'],
            'sections.present_illness.reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'sections.present_illness.illness_narrative' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'sections.systems_review' => ['sometimes', 'array:description'],
            'sections.systems_review.description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'sections.vital_signs' => ['sometimes', 'array:blood_pressure_systolic,blood_pressure_diastolic,temperature_c,heart_rate,respiratory_rate,oxygen_saturation,weight_kg,height_cm,notes'],
            'sections.vital_signs.blood_pressure_systolic' => ['sometimes', 'nullable', 'integer', 'between:60,250'],
            'sections.vital_signs.blood_pressure_diastolic' => ['sometimes', 'nullable', 'integer', 'between:30,160'],
            'sections.vital_signs.temperature_c' => ['sometimes', 'nullable', 'numeric', 'between:30,45'],
            'sections.vital_signs.heart_rate' => ['sometimes', 'nullable', 'integer', 'between:30,220'],
            'sections.vital_signs.respiratory_rate' => ['sometimes', 'nullable', 'integer', 'between:5,60'],
            'sections.vital_signs.oxygen_saturation' => ['sometimes', 'nullable', 'integer', 'between:70,100'],
            'sections.vital_signs.weight_kg' => ['sometimes', 'nullable', 'numeric', 'between:0.1,500'],
            'sections.vital_signs.height_cm' => ['sometimes', 'nullable', 'numeric', 'between:30,250'],
            'sections.vital_signs.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $sections = $this->input('sections');

        if (! is_array($sections)) {
            return;
        }

        $stringFields = [
            'patient_identification' => [
                'name', 'document_type', 'document_number', 'birth_date', 'gender', 'occupation',
                'address', 'guardian_name', 'relationship', 'phone_number', 'email', 'emergency_contact', 'insurance',
            ],
            'family_history' => ['description'],
            'non_pathological_history' => ['description'],
            'pathological_history' => ['description'],
            'gyneco_obstetric_history' => ['description'],
            'present_illness' => ['reason', 'illness_narrative'],
            'systems_review' => ['description'],
            'vital_signs' => ['notes'],
        ];

        foreach ($stringFields as $section => $fields) {
            if (! is_array($sections[$section] ?? null)) {
                continue;
            }

            foreach ($fields as $field) {
                if (array_key_exists($field, $sections[$section]) && $sections[$section][$field] !== null) {
                    $sections[$section][$field] = trim((string) $sections[$section][$field]);
                }
            }
        }

        $this->merge(['sections' => $sections]);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $systolic = data_get($this->input('sections'), 'vital_signs.blood_pressure_systolic');
            $diastolic = data_get($this->input('sections'), 'vital_signs.blood_pressure_diastolic');

            if ($systolic !== null && $diastolic !== null && (int) $diastolic >= (int) $systolic) {
                $validator->errors()->add('sections.vital_signs.blood_pressure_diastolic', 'La presión diastólica debe ser menor que la sistólica.');
            }
        }];
    }
}
