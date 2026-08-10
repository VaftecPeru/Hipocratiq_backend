<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AdminAssignableRole;
use App\Enums\ClinicStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUserAccessUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(AdminAssignableRole::class)],
            'clinic_id' => [
                'nullable',
                'integer',
                'required_unless:role,'.AdminAssignableRole::SuperAdmin->value,
                'prohibited_if:role,'.AdminAssignableRole::SuperAdmin->value,
                Rule::exists('clinics', 'id')->where('status', ClinicStatus::Active->value),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'clinic_id.required_unless' => 'Selecciona la clínica que limitará el acceso de este usuario.',
            'clinic_id.prohibited_if' => 'El Superadministrador tiene alcance global y no debe estar limitado a una clínica.',
            'clinic_id.exists' => 'La clínica seleccionada no existe o no está activa.',
        ];
    }
}
