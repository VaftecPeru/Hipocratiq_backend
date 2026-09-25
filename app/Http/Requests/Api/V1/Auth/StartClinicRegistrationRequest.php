<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Enums\AuthenticationClient;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class StartClinicRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
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
            'clinic_name' => ['required', 'string', 'max:160'],
            'tax_id' => ['required', 'string', 'max:80'],
            'country_code' => ['required', 'string', 'in:PE,MX,US'],
            'contact_phone' => ['required', 'string', 'max:30', $this->phoneRule('contact_phone')],
            'address' => ['required', 'string', 'max:240'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'admin_phone_number' => ['required', 'string', 'max:30', $this->phoneRule('admin_phone_number')],
            'password' => ['required', 'confirmed', 'max:72', Password::defaults()],
            'terms_accepted' => ['accepted'],
            'google_registration_token' => ['nullable', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'client_type' => $this->input('client_type', $this->header('X-Client-Type', AuthenticationClient::Web->value)),
            'device_name' => $this->input('device_name', $this->header('X-Device-Name')),
            'device_id' => $this->input('device_id', $this->header('X-Device-ID')),
            'admin_email' => is_string($this->input('admin_email')) ? strtolower(trim($this->input('admin_email'))) : $this->input('admin_email'),
            'tax_id' => is_string($this->input('tax_id')) ? strtoupper(trim($this->input('tax_id'))) : $this->input('tax_id'),
        ]);
    }

    private function phoneRule(string $field): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($field): void {
            try {
                PhoneNumber::from((string) $value, (string) $this->input('country_code'));
            } catch (InvalidArgumentException $exception) {
                $fail("{$field}: {$exception->getMessage()}");
            }
        };
    }
}
