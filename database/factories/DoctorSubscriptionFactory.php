<?php

namespace Database\Factories;

use App\Models\DoctorProfile;
use App\Models\DoctorSubscription;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DoctorSubscription> */
class DoctorSubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'doctor_profile_id' => DoctorProfile::factory(),
            'plan_id' => Plan::factory(),
            'billing_cycle' => 'monthly',
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => null,
            'provider' => 'simulated',
            'provider_reference' => 'sim_doc_sub_'.Str::lower(Str::random(24)),
        ];
    }
}
