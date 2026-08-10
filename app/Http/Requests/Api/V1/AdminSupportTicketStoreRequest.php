<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ClinicStatus;
use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketPriority;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminSupportTicketStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'clinic_id' => [
                'required',
                'integer',
                Rule::exists('clinics', 'id')->where('status', ClinicStatus::Active->value),
            ],
            'category' => ['required', Rule::enum(SupportTicketCategory::class)],
            'priority' => ['required', Rule::enum(SupportTicketPriority::class)],
            'subject' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:5000'],
            'contact_email' => ['required', 'email:rfc', 'max:254'],
            'notify_on_create' => ['sometimes', 'boolean'],
            'assign_to_self' => ['sometimes', 'boolean'],
        ];
    }
}
