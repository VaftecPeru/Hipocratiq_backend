<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ClinicMessageStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
            'patient_profile_id' => ['sometimes', 'nullable', 'integer', 'exists:patient_profiles,id'],
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }
}
