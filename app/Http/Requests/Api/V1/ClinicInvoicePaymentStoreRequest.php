<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClinicInvoicePaymentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'currency' => ['required', 'string', 'size:3', Rule::in($this->supportedCurrencies())],
            'payment_method' => ['required', 'string', Rule::in(['cash', 'card', 'bank_transfer', 'other'])],
            'reference' => ['nullable', 'string', 'max:120', Rule::unique('clinic_invoice_payments', 'reference')],
            'note' => ['nullable', 'string', 'max:1000'],
            'paid_at' => ['sometimes', 'date', 'before_or_equal:now'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [
            'currency' => mb_strtoupper(trim((string) $this->input('currency'))),
            'payment_method' => mb_strtolower(trim((string) $this->input('payment_method'))),
        ];

        foreach (['reference', 'note'] as $field) {
            if ($this->has($field) && $this->input($field) !== null) {
                $normalized[$field] = trim((string) $this->input($field));
            }
        }

        $this->merge($normalized);
    }

    /** @return array<int, string> */
    private function supportedCurrencies(): array
    {
        return array_values(array_filter(
            (array) config('memberships.supported_currencies', []),
            static fn (mixed $currency): bool => is_string($currency),
        ));
    }
}
