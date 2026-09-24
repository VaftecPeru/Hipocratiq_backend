<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Enums\AuthenticationClient;
use App\Enums\DoctorPracticeType;
use App\Models\Specialty;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class StartDoctorRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isClinicDoctor = $this->input('practice_type') === DoctorPracticeType::Clinic->value;
        $isMobile = $this->input('client_type') === AuthenticationClient::Mobile->value;

        return [
            'client_type' => ['required', Rule::in([AuthenticationClient::Web->value, AuthenticationClient::Mobile->value])],
            'device_name' => [Rule::requiredIf($isMobile), 'nullable', 'string', 'max:100', 'not_regex:/^\s*$/'],
            'device_id' => [
                Rule::requiredIf($isMobile),
                'nullable',
                'string',
                'min:16',
                'max:255',
                'regex:/\A[A-Za-z0-9._~:-]+\z/',
            ],
            'doctor_name' => ['required', 'string', 'max:120'],
            'doctor_email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'country_code' => ['required', 'string', 'in:PE,MX,US'],
            'doctor_phone_number' => ['required', 'string', 'max:30', $this->phoneRule()],
            'medical_license' => ['required', 'string', 'max:80', 'unique:doctor_profiles,medical_license', $this->professionalLicenseRule()],
            'specialty_license' => ['nullable', 'string', 'max:80', 'unique:doctor_profiles,specialty_license', $this->professionalLicenseRule(nullable: true)],
            'specialty' => ['nullable', 'string', 'max:120'],
            'specialty_id' => ['nullable', 'integer', Rule::exists('specialties', 'id')->where('is_active', true)->whereNull('parent_id')],
            'subspecialty_id' => ['nullable', 'integer', Rule::exists('specialties', 'id')->where('is_active', true)],
            'practice_type' => ['required', Rule::enum(DoctorPracticeType::class)],
            'clinic_id' => [Rule::requiredIf($isClinicDoctor), 'nullable', 'integer', Rule::exists('clinics', 'id')->where('status', 'active')],
            'password' => ['required', 'confirmed', 'max:72', Password::defaults()],
            'terms_accepted' => ['accepted'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $specialtyId = $this->integer('specialty_id');
            $subspecialtyId = $this->integer('subspecialty_id');

            if ($specialtyId === 0 && trim((string) $this->input('specialty')) === '') {
                $validator->errors()->add('specialty_id', 'Selecciona una especialidad o ingresa una especialidad válida.');
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

    protected function prepareForValidation(): void
    {
        $this->merge([
            'client_type' => $this->input('client_type', $this->header('X-Client-Type', AuthenticationClient::Web->value)),
            'device_name' => $this->input('device_name', $this->header('X-Device-Name')),
            'device_id' => $this->input('device_id', $this->header('X-Device-ID')),
            'doctor_email' => is_string($this->input('doctor_email')) ? strtolower(trim($this->input('doctor_email'))) : $this->input('doctor_email'),
            'medical_license' => is_string($this->input('medical_license')) ? strtoupper(trim($this->input('medical_license'))) : $this->input('medical_license'),
            'specialty_license' => is_string($this->input('specialty_license')) && trim($this->input('specialty_license')) !== ''
                ? strtoupper(trim($this->input('specialty_license')))
                : null,
        ]);
    }

    private function professionalLicenseRule(bool $nullable = false): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($nullable): void {
            if ($nullable && ($value === null || $value === '')) {
                return;
            }

            if ($this->input('country_code') !== 'MX') {
                return;
            }

            if (! is_string($value) || preg_match('/^\\d+$/', $value) !== 1) {
                $fail('Para México, la cédula profesional debe registrarse con su número oficial emitido por la SEP/DGP.');
            }
        };
    }

    private function phoneRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            try {
                PhoneNumber::from((string) $value, (string) $this->input('country_code'));
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
