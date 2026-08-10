<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $userId = $this->route('user')?->getKey();

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254', Rule::unique('users', 'email')->ignore($userId)],
            'phone_number' => ['sometimes', 'string', 'max:32', Rule::unique('users', 'phone_number')->ignore($userId)],
            'country_code' => ['sometimes', 'string', Rule::in(['MX', 'PE', 'US'])],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
        ];
    }
}
