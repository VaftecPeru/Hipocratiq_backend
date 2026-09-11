<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClinicInvoiceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $clinicId = $this->attributes->get('clinic')?->getKey();

        return [
            'patient_id' => ['required', 'integer', 'min:1'],
            'invoice_number' => [
                'required',
                'string',
                'max:64',
                Rule::unique('clinic_invoices', 'invoice_number')->where(
                    fn ($query) => $query->where('clinic_id', $clinicId),
                ),
            ],
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'currency' => ['required', 'string', 'size:3', Rule::in($this->supportedCurrencies())],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'min:1', 'max:200'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [
            'invoice_number' => trim((string) $this->input('invoice_number')),
            'currency' => mb_strtoupper(trim((string) $this->input('currency'))),
        ];

        $items = $this->input('items');

        if (is_array($items)) {
            $normalized['items'] = array_map(
                static function (mixed $item): mixed {
                    if (! is_array($item) || ! array_key_exists('description', $item)) {
                        return $item;
                    }

                    $item['description'] = trim((string) $item['description']);

                    return $item;
                },
                $items,
            );
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
