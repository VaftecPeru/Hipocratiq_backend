<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PatientConsentScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatientConsentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(PatientConsentScope::class)],
            'policy_version' => [
                'required',
                'string',
                'max:40',
                Rule::in([(string) config('clinical.consent_policy_version')]),
            ],
            'effective_until' => ['nullable', 'date', 'after:now'],
        ];
    }
}
