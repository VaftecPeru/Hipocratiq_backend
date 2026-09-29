<?php

namespace App\Http\Requests\Api\V1;

use App\Models\PatientProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DoctorPatientUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $patient = $this->route('patient');
        $patientId = $patient instanceof PatientProfile ? $patient->id : null;
        $userId = $patient instanceof PatientProfile ? $patient->user_id : null;

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254', Rule::unique('users', 'email')->ignore($userId)],
            'phone_number' => ['sometimes', 'required', 'string', 'max:32'],
            'country_code' => ['sometimes', 'required', 'string', Rule::in(['MX', 'PE', 'US'])],
            'document_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'document_number' => ['sometimes', 'nullable', 'string', 'max:80', Rule::unique('patient_profiles', 'document_number')->ignore($patientId)],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'sex' => ['sometimes', 'nullable', 'string', Rule::in(['Femenino', 'Masculino', 'Otro'])],
            'address' => ['sometimes', 'nullable', 'string', 'max:240'],
            'emergency_contact' => ['sometimes', 'nullable', 'string', 'max:160'],
            'insurance' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_returning_patient' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['name', 'document_type', 'address', 'emergency_contact', 'insurance'] as $field) {
            if ($this->has($field)) {
                $normalized[$field] = $this->input($field) === null ? null : trim((string) $this->input($field));
            }
        }

        if ($this->has('email')) {
            $email = trim((string) $this->input('email'));
            $normalized['email'] = $email === '' ? null : mb_strtolower($email);
        }

        if ($this->has('document_number')) {
            $documentNumber = trim((string) $this->input('document_number'));
            $normalized['document_number'] = $documentNumber === '' ? null : mb_strtoupper($documentNumber);
        }

        if ($this->has('country_code')) {
            $normalized['country_code'] = mb_strtoupper(trim((string) $this->input('country_code')));
        }

        $this->merge($normalized);
    }
}
