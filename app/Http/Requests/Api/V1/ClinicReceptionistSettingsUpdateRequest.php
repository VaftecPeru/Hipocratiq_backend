<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClinicReceptionistSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array'],
            'preferences.new_appointments' => ['sometimes', 'boolean'],
            'preferences.arrivals' => ['sometimes', 'boolean'],
            'preferences.pending_payments' => ['sometimes', 'boolean'],
            'preferences.internal_messages' => ['sometimes', 'boolean'],
            'preferences.documents' => ['sometimes', 'boolean'],
            'preferences.online_reception' => ['sometimes', 'boolean'],
            'preferences.whatsapp' => ['sometimes', 'boolean'],
            'preferences.auto_print' => ['sometimes', 'boolean'],
            'preferences.duplicate_tickets' => ['sometimes', 'boolean'],
            'preferences.shift_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'preferences.shift_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'preferences.shift_end' => ['sometimes', 'nullable', 'date_format:H:i'],
            'preferences.counter_name' => ['sometimes', 'nullable', 'string', 'max:40'],
            'preferences.printer_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'preferences.paper_size' => ['sometimes', 'nullable', Rule::in(['A4', 'letter'])],
        ];
    }
}
