<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Enums\AuthenticationClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResendOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isMobile = $this->input('client_type') === AuthenticationClient::Mobile->value;

        return [
            'challenge_id' => ['required', 'uuid'],
            'client_type' => ['required', Rule::enum(AuthenticationClient::class)],
            'device_name' => [Rule::requiredIf($isMobile), 'nullable', 'string', 'max:100', 'not_regex:/^\s*$/'],
            'device_id' => [
                Rule::requiredIf($isMobile),
                'nullable',
                'string',
                'min:16',
                'max:255',
                'regex:/\A[A-Za-z0-9._~:-]+\z/',
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
