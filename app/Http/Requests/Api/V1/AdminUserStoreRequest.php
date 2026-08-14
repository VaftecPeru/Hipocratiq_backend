<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AdminAssignableRole;
use App\Enums\ClinicStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUserStoreRequest extends FormRequest
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
            'role' => ['required', Rule::in([
                AdminAssignableRole::SuperAdmin->value,
                AdminAssignableRole::ClinicAdmin->value,
                AdminAssignableRole::Receptionist->value,
            ])],
            'clinic_id' => [
                'nullable',
                'integer',
                'required_if:role,'.AdminAssignableRole::ClinicAdmin->value,
                'required_if:role,'.AdminAssignableRole::Receptionist->value,
                'prohibited_if:role,'.AdminAssignableRole::SuperAdmin->value,
                Rule::exists('clinics', 'id')->where('status', ClinicStatus::Active->value),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'role.in' => 'Desde Superadmin sólo se pueden crear accesos administrativos. Médicos y pacientes deben completar su registro especializado.',
            'clinic_id.required_if' => 'Selecciona la clínica del nuevo administrador.',
            'clinic_id.exists' => 'La clínica seleccionada no existe o no está activa.',
        ];
    }
}
