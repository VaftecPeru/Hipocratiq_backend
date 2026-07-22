<?php

namespace Database\Factories;

use App\Models\ClinicSubscription;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPayment>
 */
class SubscriptionPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_subscription_id' => ClinicSubscription::factory(),
            'idempotency_key' => fake()->unique()->uuid(),
            'amount_cents' => 4900,
            'currency' => 'USD',
            'status' => 'succeeded',
            'provider' => 'simulated',
            'provider_reference' => fake()->unique()->uuid(),
            'paid_at' => now(),
        ];
    }
}
