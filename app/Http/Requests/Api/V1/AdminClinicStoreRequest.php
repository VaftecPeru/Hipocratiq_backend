<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BillingCycle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminClinicStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'tax_id' => ['required', 'string', 'min:3', 'max:64', Rule::unique('clinics', 'tax_id')],
            'country_code' => ['required', 'string', Rule::in(['MX', 'PE', 'US'])],
            'contact_phone' => ['required', 'string', 'max:32'],
            'address' => ['required', 'string', 'min:2', 'max:255'],
            'plan' => ['required', 'string', Rule::exists('plans', 'key')->where('is_active', true)],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
        ];
    }
}
