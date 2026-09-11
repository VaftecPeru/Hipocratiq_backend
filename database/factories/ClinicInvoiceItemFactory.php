<?php

namespace Database\Factories;

use App\Models\ClinicInvoice;
use App\Models\ClinicInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicInvoiceItem>
 */
class ClinicInvoiceItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_invoice_id' => ClinicInvoice::factory(),
            'description' => 'Consulta médica',
            'quantity' => 1,
            'unit_amount_minor' => 1000,
            'line_total_minor' => 1000,
        ];
    }
}
