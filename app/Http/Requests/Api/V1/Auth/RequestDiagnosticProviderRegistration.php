<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class RequestDiagnosticProviderRegistration extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Sólo solicitud PENDING, nunca crea usuario ni permiso clínico.
    }

    public function rules(): array
    {
        return [
            'provider_type' => ['required', Rule::in(['laboratory', 'imaging_center', 'cabinet'])],
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'country_code' => ['required', Rule::in(['MX', 'PE', 'US'])],
            'tax_id' => [
                'required', 'string', 'min:6', 'max:80',
                Rule::unique('diagnostic_providers', 'tax_id')
                    ->where('country_code', $this->input('country_code')),
            ],
            'contact_name' => ['required', 'string', 'min:3', 'max:160'],
            'contact_email' => ['required', 'email:rfc', 'max:254'],
            'contact_phone' => [
                'required', 'string', 'max:32',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    try {
                        PhoneNumber::from((string) $value, (string) $this->input('country_code'));
                    } catch (InvalidArgumentException) {
                        $fail('El teléfono no es válido para el país elegido.');
                    }
                },
            ],
            'terms_accepted' => ['accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['name', 'tax_id', 'contact_name', 'contact_email', 'country_code', 'contact_phone'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim($this->input($field));
            }
        }

        if (isset($normalized['tax_id'])) {
            $normalized['tax_id'] = mb_strtoupper($normalized['tax_id']);
        }
        if (isset($normalized['country_code'])) {
            $normalized['country_code'] = strtoupper($normalized['country_code']);
        }
        if (isset($normalized['contact_email'])) {
            $normalized['contact_email'] = mb_strtolower($normalized['contact_email']);
        }
        $this->merge($normalized);
    }
}
