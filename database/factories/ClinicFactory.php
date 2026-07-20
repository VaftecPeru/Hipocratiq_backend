<?php

namespace Database\Factories;

use App\Models\Clinic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Clinic>
 */
class ClinicFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Clínica',
            'slug' => fake()->unique()->slug(),
            'tax_id' => fake()->unique()->bothify('RFC-########'),
            'country_code' => 'MX',
            'contact_phone' => '+5255'.fake()->numerify('########'),
            'address' => fake()->address(),
            'status' => 'active',
        ];
    }
}
