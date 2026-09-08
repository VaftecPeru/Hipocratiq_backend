<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SystemIncidentStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminSystemIncidentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(SystemIncidentStatus::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
