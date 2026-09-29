<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DoctorPatientStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
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
            'insurance' => ['nullable', 'string', 'max:120'],
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
