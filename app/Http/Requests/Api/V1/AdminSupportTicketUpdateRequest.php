<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminSupportTicketUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(SupportTicketStatus::class)],
            'priority' => ['sometimes', Rule::enum(SupportTicketPriority::class)],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }
}
