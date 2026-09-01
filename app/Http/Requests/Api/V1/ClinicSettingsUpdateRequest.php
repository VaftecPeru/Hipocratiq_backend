<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ClinicSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'contact_phone' => ['sometimes', 'string', 'max:32'],
            'address' => ['sometimes', 'string', 'max:240'],
            'preferences' => ['sometimes', 'array'],
            'preferences.maintenance' => ['sometimes', 'boolean'],
            'preferences.audit' => ['sometimes', 'boolean'],
            'preferences.signatures' => ['sometimes', 'boolean'],
            'preferences.backups' => ['sometimes', 'boolean'],
        ];
    }
}
