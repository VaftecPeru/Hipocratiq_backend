<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ClinicInvoiceStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClinicInvoiceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'patient_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(array_map(
                static fn (ClinicInvoiceStatus $status): string => $status->value,
                ClinicInvoiceStatus::cases(),
            ))],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in($this->supportedCurrencies())],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('search')) {
            $normalized['search'] = trim((string) $this->input('search'));
        }

        if ($this->has('currency')) {
            $normalized['currency'] = mb_strtoupper(trim((string) $this->input('currency')));
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
