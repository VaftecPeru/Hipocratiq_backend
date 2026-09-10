<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\ClinicInvoice;
use App\Models\PatientProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicInvoice>
 */
class ClinicInvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'patient_profile_id' => PatientProfile::factory(),
            'invoice_number' => 'INV-'.$this->faker->unique()->numerify('######'),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'total_minor' => 1000,
            'currency' => 'USD',
            'status' => 'issued',
        ];
    }
}
