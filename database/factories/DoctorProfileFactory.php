<?php

namespace Database\Factories;

use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoctorProfile> */
class DoctorProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'clinic_id' => null,
            'medical_license' => fake()->unique()->numerify('########'),
            'medical_license_country_code' => 'MX',
            'medical_license_issuer' => 'SEP-DGP',
            'medical_license_verification_status' => 'unverified',
            'specialty' => fake()->randomElement(['Medicina general', 'Cardiología', 'Pediatría']),
            'practice_type' => 'independent',
        ];
    }
}
