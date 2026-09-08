<?php

namespace App\Http\Requests\Api\V1;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class DoctorProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $profileId = $this->user()?->doctorProfile?->id;

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($this->user()?->id)],
            'country_code' => ['sometimes', 'string', Rule::in(['MX', 'PE', 'US'])],
            'phone_number' => ['sometimes', 'string', 'max:30', $this->phoneRule()],
            'medical_license' => ['sometimes', 'string', 'max:80', Rule::unique('doctor_profiles', 'medical_license')->ignore($profileId)],
            'specialty' => ['sometimes', 'string', 'min:2', 'max:120'],
            'specialty_id' => ['sometimes', 'nullable', 'integer', Rule::exists('specialties', 'id')->where('is_active', true)->whereNull('parent_id')],
            'subspecialty_id' => ['sometimes', 'nullable', 'integer', Rule::exists('specialties', 'id')->where('is_active', true)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $input = [];

        if ($this->has('medical_license')) {
            $input['medical_license'] = is_string($this->input('medical_license')) ? strtoupper(trim($this->input('medical_license'))) : $this->input('medical_license');
        }

        if ($this->has('email')) {
            $input['email'] = is_string($this->input('email')) && trim($this->input('email')) !== '' ? strtolower(trim($this->input('email'))) : null;
        }

        $this->merge($input);
    }

    private function phoneRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $countryCode = (string) ($this->input('country_code') ?? $this->user()?->country_code);
            try {
                PhoneNumber::from((string) $value, $countryCode);
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
