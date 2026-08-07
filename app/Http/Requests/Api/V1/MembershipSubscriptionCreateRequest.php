<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BillingCycle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipSubscriptionCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'clinic_id' => ['required', 'integer', Rule::exists('clinics', 'id')],
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'starts_at' => ['required', 'date'],
            'auto_renew' => ['sometimes', 'boolean'],
        ];
    }
}
