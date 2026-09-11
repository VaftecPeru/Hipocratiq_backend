<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use App\Support\Auth\LoginRole;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required_without:phone_number', 'nullable', 'string', 'max:255', $this->identifierRule()],
            'country_code' => ['required_with:phone_number', 'nullable', 'string', 'in:PE,MX,US'],
            'phone_number' => [
                'required_without:identifier',
                'nullable',
                'string',
                'max:30',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    try {
                        PhoneNumber::from((string) $value, (string) $this->input('country_code'));
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
            'client_type' => ['required', Rule::in([AuthenticationClient::Web->value])],
            'actor_context' => [
                'required',
                Rule::enum(AuthenticationContext::class)->only([
                    AuthenticationContext::Patient,
                    AuthenticationContext::Staff,
                ]),
            ],
            'requested_role' => ['nullable', 'string', 'max:32'],
            'password' => $this->input('actor_context') === AuthenticationContext::Patient->value
                ? ['prohibited']
                : ['required', 'string', 'max:72'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $identifier = $this->input('identifier');
        $requestedRole = $this->input('requested_role');

        $this->merge([
            'identifier' => is_string($identifier) && str_contains($identifier, '@')
                ? strtolower(trim($identifier))
                : $identifier,
            'client_type' => $this->input('client_type', AuthenticationClient::Web->value),
            'actor_context' => $this->input('actor_context', AuthenticationContext::Staff->value),
            'requested_role' => LoginRole::normalize(is_string($requestedRole) ? $requestedRole : null),
        ]);
    }

    private function identifierRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null) {
                return;
            }

            if (is_string($value) && str_contains($value, '@')) {
                if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $fail('El identificador debe ser un correo o teléfono E.164 válido.');
                }

                return;
            }

            try {
                PhoneNumber::fromE164((string) $value);
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
