<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminEmergencyPolicyUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'visibility' => ['required', Rule::in(['reception_doctors_admins', 'doctors_admins', 'admins_only'])],
            'label' => ['sometimes', 'string', 'min:2', 'max:60'],
            'color' => ['required', Rule::in(['#EF2F3F', '#F97316', '#7C3AED'])],
            'inheritance' => ['required', Rule::in(['workflow_and_responsibles', 'responsibles_only', 'locked'])],
            'default_channel' => ['required', Rule::in(['priority_push', 'internal_alert', 'email', 'sms'])],
            'escalation_minutes' => ['required', 'integer', Rule::in([1, 3, 5, 10])],
            'responsibles' => ['required', 'array', 'min:1', 'max:10'],
            'responsibles.*.role' => ['required', 'string', 'max:100'],
            'responsibles.*.channel' => ['required', 'string', 'max:160'],
        ];
    }
}
