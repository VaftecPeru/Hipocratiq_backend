<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminDoctorCredentialVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'credential_type' => ['required', Rule::in(['professional', 'specialty'])],
            'status' => ['required', Rule::in(['verified', 'rejected'])],
            'reference' => ['nullable', 'string', 'max:255', 'required_if:status,verified'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
