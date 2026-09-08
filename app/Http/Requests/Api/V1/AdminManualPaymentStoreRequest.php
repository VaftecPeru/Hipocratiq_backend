<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminManualPaymentStoreRequest extends FormRequest
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
            'clinic_subscription_id' => ['required', 'integer', Rule::exists('clinic_subscriptions', 'id')],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'currency' => ['required', 'string', 'size:3', Rule::in(['USD', 'MXN', 'PEN'])],
            'reference' => ['required', 'string', 'max:120', Rule::unique('subscription_payments', 'provider_reference')],
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
