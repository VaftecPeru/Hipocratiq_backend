<?php

namespace App\Http\Requests\Api\V1\Auth;

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
        return [
            'purpose' => ['required', Rule::enum(OtpPurpose::class)],
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'digits:6'],
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
}
