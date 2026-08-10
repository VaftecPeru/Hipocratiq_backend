<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdminNotificationReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'notification_ids' => ['required', 'array', 'min:1', 'max:100'],
            'notification_ids.*' => ['required', 'string', 'regex:/^(audit|incident|support):[1-9][0-9]*$/'],
        ];
    }
}
