<?php

namespace Database\Factories;

use App\Enums\PrescriptionStatus;
use App\Models\Clinic;
use App\Models\DoctorPrescription;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<DoctorPrescription> */
class DoctorPrescriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'doctor_user_id' => User::factory(),
            'patient_profile_id' => PatientProfile::factory(),
            'consultation_id' => null,
            'medication' => 'Metformina 850 mg',
            'dosage' => '1 tableta',
            'frequency' => 'Cada 12 horas',
            'duration_days' => 30,
            'status' => PrescriptionStatus::Active,
            'issued_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays(30),
            'notes' => null,
        ];
    }
}
