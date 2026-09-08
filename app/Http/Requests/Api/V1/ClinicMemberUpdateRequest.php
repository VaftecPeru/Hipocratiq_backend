<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClinicMemberUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        /** @var User|null $member */
        $member = $this->route('member');

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254', Rule::unique('users', 'email')->ignore($member?->id)],
            'phone_number' => ['sometimes', 'required', 'string', 'max:32'],
            'country_code' => ['sometimes', 'required', 'string', Rule::in(['MX', 'PE', 'US'])],
        ];
    }
}
