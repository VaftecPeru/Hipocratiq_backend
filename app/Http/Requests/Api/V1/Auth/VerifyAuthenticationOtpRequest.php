<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Enums\AuthenticationClient;
use App\Enums\OtpPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class VerifyAuthenticationOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isMobile = $this->input('client_type') === AuthenticationClient::Mobile->value;

        return [
            'purpose' => ['required', Rule::enum(OtpPurpose::class)],
            'client_type' => ['required', Rule::enum(AuthenticationClient::class)],
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'digits:6'],
            'device_name' => [Rule::requiredIf($isMobile), 'nullable', 'string', 'max:100', 'not_regex:/^\s*$/'],
            'device_id' => [
                Rule::requiredIf($isMobile),
                'nullable',
                'string',
                'min:16',
                'max:255',
                'regex:/\A[A-Za-z0-9._~:-]+\z/',
            ],
            'password' => [
                Rule::requiredIf($this->input('purpose') === OtpPurpose::PasswordReset->value),
                'nullable',
                'string',
                'confirmed',
                'max:72',
                Password::defaults(),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'client_type' => $this->input('client_type', $this->header('X-Client-Type', AuthenticationClient::Web->value)),
            'device_name' => $this->input('device_name', $this->header('X-Device-Name')),
            'device_id' => $this->input('device_id', $this->header('X-Device-ID')),
        ]);
    }
}
