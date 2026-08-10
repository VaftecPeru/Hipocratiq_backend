<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminSupportTicketIndexRequest extends FormRequest
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
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'clinic_id' => ['sometimes', 'integer', Rule::exists('clinics', 'id')],
            'category' => ['sometimes', Rule::enum(SupportTicketCategory::class)],
            'priority' => ['sometimes', Rule::enum(SupportTicketPriority::class)],
            'status' => ['sometimes', Rule::enum(SupportTicketStatus::class)],
            'country_code' => ['sometimes', 'string', 'size:2', Rule::exists('clinics', 'country_code')],
            'assigned_to_user_id' => ['sometimes', 'integer', Rule::exists('users', 'id')],
            'overdue' => ['sometimes', 'boolean'],
        ];
    }
}
