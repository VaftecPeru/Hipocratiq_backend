<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicMembership>
 */
class ClinicMembershipFactory extends Factory
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
            'user_id' => User::factory(),
            'role_id' => Role::factory(),
            'status' => 'active',
        ];
    }
}
