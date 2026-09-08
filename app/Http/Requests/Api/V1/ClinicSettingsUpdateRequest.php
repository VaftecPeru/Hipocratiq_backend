<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClinicSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'contact_phone' => ['sometimes', 'string', 'max:32'],
            'address' => ['sometimes', 'string', 'max:240'],
            'preferences' => ['sometimes', 'array'],
            'preferences.maintenance' => ['sometimes', 'boolean'],
            'preferences.audit' => ['sometimes', 'boolean'],
            'preferences.signatures' => ['sometimes', 'boolean'],
            'preferences.backups' => ['sometimes', 'boolean'],
            'preferences.emergency' => ['sometimes', 'array'],
            'preferences.emergency.enabled' => ['sometimes', 'boolean'],
            'preferences.emergency.visibility' => ['sometimes', Rule::in(['reception_doctors_admins', 'doctors_admins', 'admins_only'])],
            'preferences.emergency.label' => ['sometimes', 'string', 'min:2', 'max:60'],
            'preferences.emergency.color' => ['sometimes', Rule::in(['#EF2F3F', '#F97316', '#7C3AED'])],
            'preferences.emergency.inheritance' => ['sometimes', Rule::in(['workflow_and_responsibles', 'responsibles_only', 'locked'])],
            'preferences.emergency.default_channel' => ['sometimes', Rule::in(['priority_push', 'internal_alert', 'email', 'sms'])],
            'preferences.emergency.escalation_minutes' => ['sometimes', 'integer', Rule::in([1, 3, 5, 10])],
            'preferences.emergency.responsibles' => ['sometimes', 'array', 'min:1', 'max:10'],
            'preferences.emergency.responsibles.*.role' => ['required_with:preferences.emergency.responsibles', 'string', 'max:100'],
            'preferences.emergency.responsibles.*.channel' => ['required_with:preferences.emergency.responsibles', 'string', 'max:160'],
        ];
    }
}
