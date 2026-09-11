<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClinicBillingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in($this->supportedCurrencies())],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge([
                'currency' => mb_strtoupper(trim((string) $this->input('currency'))),
            ]);
        }
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
