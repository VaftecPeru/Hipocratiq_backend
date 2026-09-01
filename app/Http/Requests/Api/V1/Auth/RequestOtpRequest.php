<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use App\Enums\OtpPurpose;
use App\Support\Auth\LoginRole;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isRegistration = $this->input('purpose') === OtpPurpose::Registration->value;
        $isStaffLogin = $this->input('purpose') === OtpPurpose::Login->value
            && $this->input('actor_context') === AuthenticationContext::Staff->value;
        $isMobile = $this->input('client_type') === AuthenticationClient::Mobile->value;
        $passwordRules = match (true) {
            $isStaffLogin => ['required', 'string', 'max:72'],
            $isRegistration => ['nullable', 'string', 'confirmed', 'max:72', Password::defaults()],
            default => ['prohibited'],
        };

        return [
            'purpose' => ['required', Rule::enum(OtpPurpose::class)],
            'client_type' => ['required', Rule::enum(AuthenticationClient::class)],
            'actor_context' => [
                'required',
                Rule::enum(AuthenticationContext::class)->only([
                    AuthenticationContext::Patient,
                    AuthenticationContext::Staff,
                ]),
            ],
            'requested_role' => [
                'nullable',
                'string',
                'max:32',
            ],
            'identifier' => [Rule::requiredIf(! $isRegistration), 'nullable', 'string', 'max:255', $this->identifierRule()],
            'name' => [Rule::requiredIf($isRegistration), 'nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:255', 'unique:users,email'],
            'country_code' => ['nullable', 'string', 'in:PE,MX,US'],
            'clinic_id' => [
                Rule::requiredIf($isRegistration),
                'nullable',
                'integer',
                Rule::exists('clinics', 'id')->where('status', 'active'),
            ],
            'phone_number' => [Rule::requiredIf($isRegistration), 'nullable', 'string', 'max:30', $this->phoneRule()],
            'password' => $passwordRules,
            'device_name' => [Rule::requiredIf($isMobile), 'nullable', 'string', 'max:100'],
            'device_id' => [Rule::requiredIf($isMobile), 'nullable', 'string', 'max:255'],
            'terms_accepted' => [$isRegistration ? 'accepted' : 'prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $purpose = (string) $this->input('purpose');
        $email = $this->input('email');
        $identifier = $this->input('identifier');
        $requestedRole = $this->input('requested_role');

        $this->merge([
            'client_type' => $this->input('client_type', AuthenticationClient::Web->value),
            'actor_context' => $this->input(
                'actor_context',
                $purpose === OtpPurpose::Registration->value
                    ? AuthenticationContext::Patient->value
                    : AuthenticationContext::Staff->value,
            ),
            'email' => is_string($email) ? strtolower($email) : $email,
            'identifier' => is_string($identifier) && str_contains($identifier, '@')
                ? strtolower($identifier)
                : $identifier,
            'requested_role' => LoginRole::normalize(is_string($requestedRole) ? $requestedRole : null),
        ]);
    }

    private function identifierRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || str_contains($value, '@')) {
                if (! is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $fail('El identificador debe ser un correo o teléfono E.164 válido.');
                }

                return;
            }

            try {
                PhoneNumber::fromE164($value);
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        };
    }

    private function phoneRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            try {
                if ($this->filled('country_code')) {
                    PhoneNumber::from((string) $value, (string) $this->input('country_code'));
                } else {
                    PhoneNumber::fromE164((string) $value);
                }
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
