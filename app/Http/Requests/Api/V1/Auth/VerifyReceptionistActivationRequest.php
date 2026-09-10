<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Enums\AuthenticationClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class VerifyReceptionistActivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invitation_token' => ['required', 'string', 'size:64', 'regex:/\A[A-Za-z0-9]+\z/'],
            'client_type' => ['required', Rule::in([AuthenticationClient::Mobile->value])],
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'digits:6'],
            'device_name' => ['required', 'string', 'max:100', 'not_regex:/^\s*$/'],
            'device_id' => ['required', 'string', 'min:16', 'max:255', 'regex:/\A[A-Za-z0-9._~:-]+\z/'],
            'password' => ['required', 'string', 'confirmed', 'max:72', Password::defaults()],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'client_type' => $this->input('client_type', $this->header('X-Client-Type')),
            'device_name' => $this->input('device_name', $this->header('X-Device-Name')),
            'device_id' => $this->input('device_id', $this->header('X-Device-ID')),
        ]);
    }
}
