<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\ClinicalHistoryRecord;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalHistoryRecord>
 */
class ClinicalHistoryRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'patient_profile_id' => PatientProfile::factory(),
            'doctor_user_id' => User::factory(),
            'appointment_id' => null,
            'created_by_user_id' => null,
            'source' => 'consultation',
            'objective' => null,
        ];
    }
}
