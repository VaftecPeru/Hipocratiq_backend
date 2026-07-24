<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class RegisterPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:255', 'unique:users,email'],
            'country_code' => ['required', 'string', 'in:PE,MX,US'],
            'clinic_id' => [
                'required',
                'integer',
                Rule::exists('clinics', 'id')->where('status', 'active'),
            ],
            'phone_number' => [
                'required',
                'string',
                'max:30',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    try {
                        PhoneNumber::from((string) $value, (string) $this->input('country_code'));
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
            'password' => ['nullable', 'confirmed', 'max:72', Password::defaults()],
            'terms_accepted' => ['accepted'],
        ];
    }
}
