<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SystemIncidentSeverity;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminSystemIncidentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:4', 'max:180'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'severity' => ['required', Rule::enum(SystemIncidentSeverity::class)],
            'service_key' => ['nullable', Rule::in(['api', 'database', 'cache', 'queue', 'storage', 'platform'])],
            'country_code' => ['nullable', Rule::in(['MX', 'PE', 'US'])],
        ];
    }
}
