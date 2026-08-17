<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClinicPatientStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['nullable', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
            'phone_number' => ['required', 'string', 'max:32'],
            'country_code' => ['required', 'string', Rule::in(['MX', 'PE', 'US'])],
        ];
    }
}
