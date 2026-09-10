<?php

namespace Database\Factories;

use App\Models\ClinicInvoice;
use App\Models\ClinicInvoicePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicInvoicePayment>
 */
class ClinicInvoicePaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_invoice_id' => ClinicInvoice::factory(),
            'amount_minor' => 500,
            'currency' => 'USD',
            'status' => 'confirmed',
            'payment_method' => 'bank_transfer',
            'reference' => 'PAY-'.$this->faker->unique()->numerify('######'),
            'note' => null,
            'paid_at' => now(),
            'recorded_by_user_id' => null,
        ];
    }
}
