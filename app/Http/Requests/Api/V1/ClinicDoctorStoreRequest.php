<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Specialty;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ClinicDoctorStoreRequest extends FormRequest
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
            'medical_license' => ['required', 'string', 'max:80', Rule::unique('doctor_profiles', 'medical_license')],
            'specialty_id' => [
                'required',
                'integer',
                Rule::exists('specialties', 'id')->where('is_active', true)->whereNull('parent_id'),
            ],
            'subspecialty_id' => [
                'nullable',
                'integer',
                Rule::exists('specialties', 'id')->where('is_active', true),
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $specialtyId = $this->positiveInteger($this->input('specialty_id'));

            if (! $this->filled('subspecialty_id')) {
                return;
            }

            $subspecialtyId = $this->positiveInteger($this->input('subspecialty_id'));

            if (! is_int($subspecialtyId)) {
                return;
            }

            if (! is_int($specialtyId)) {
                $validator->errors()->add('specialty_id', 'Selecciona la especialidad principal de la subespecialidad.');

                return;
            }

            if (! Specialty::query()
                ->whereKey($subspecialtyId)
                ->where('parent_id', $specialtyId)
                ->where('is_active', true)
                ->exists()) {
                $validator->errors()->add('subspecialty_id', 'La subespecialidad no pertenece a la especialidad seleccionada.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('name') && is_string($this->input('name'))) {
            $normalized['name'] = trim($this->input('name'));
        }

        if ($this->has('email')) {
            $email = $this->input('email');
            if (is_string($email)) {
                $email = trim($email);
                $normalized['email'] = $email === '' ? null : mb_strtolower($email);
            }
        }

        if ($this->has('country_code') && is_string($this->input('country_code'))) {
            $normalized['country_code'] = mb_strtoupper(trim($this->input('country_code')));
        }

        if ($this->has('medical_license') && is_string($this->input('medical_license'))) {
            $license = trim($this->input('medical_license'));
            $normalized['medical_license'] = $license === '' ? null : mb_strtoupper($license);
        }

        $this->merge($normalized);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($validated) ? $validated : null;
    }
}
