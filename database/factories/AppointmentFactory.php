<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
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
            'patient_profile_id' => PatientProfile::factory(),
            'doctor_user_id' => User::factory(),
            'scheduled_at' => Carbon::now()->addDay()->setTime(9, 0),
            'status' => AppointmentStatus::Confirmed,
            'appointment_type' => 'consultation',
            'notes' => null,
        ];
    }
}
