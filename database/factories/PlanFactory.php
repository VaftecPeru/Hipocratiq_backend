<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'basic',
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'monthly_price_cents' => fake()->numberBetween(2900, 14900),
            'annual_price_cents' => fake()->numberBetween(29000, 149000),
            'currency' => 'USD',
            'features' => [fake()->sentence()],
            'publicly_selectable' => true,
            'is_active' => true,
        ];
    }
}
