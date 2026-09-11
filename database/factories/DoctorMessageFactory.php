<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\DoctorMessage;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoctorMessage> */
class DoctorMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'sender_user_id' => User::factory(),
            'recipient_user_id' => User::factory(),
            'patient_profile_id' => PatientProfile::factory(),
            'body' => fake()->sentence(),
            'read_at' => null,
        ];
    }
}
