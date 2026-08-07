<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicSubscription>
 */
class ClinicSubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'plan_id' => Plan::factory(),
            'billing_cycle' => 'monthly',
            'status' => 'active',
            'auto_renew' => fake()->boolean(85),
            'starts_at' => now(),
            'ends_at' => null,
            'provider' => 'simulated',
            'provider_reference' => fake()->unique()->uuid(),
        ];
    }
}
