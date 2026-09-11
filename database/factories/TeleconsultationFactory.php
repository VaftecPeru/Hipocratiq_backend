<?php

namespace Database\Factories;

use App\Enums\TeleconsultationStatus;
use App\Models\Clinic;
use App\Models\PatientProfile;
use App\Models\Teleconsultation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<Teleconsultation> */
class TeleconsultationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'doctor_user_id' => User::factory(),
            'patient_profile_id' => PatientProfile::factory(),
            'appointment_id' => null,
            'consultation_id' => null,
            'status' => TeleconsultationStatus::Scheduled,
            'scheduled_at' => Carbon::now()->addDay()->setTime(10, 0),
            'started_at' => null,
            'ended_at' => null,
            'notes' => null,
        ];
    }
}
