<?php

namespace Database\Factories;

use App\Enums\ConsultationStatus;
use App\Models\Clinic;
use App\Models\Consultation;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consultation>
 */
class ConsultationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'doctor_user_id' => User::factory(),
            'patient_profile_id' => PatientProfile::factory(),
            'appointment_id' => null,
            'clinical_history_record_id' => null,
            'created_by_user_id' => null,
            'status' => ConsultationStatus::InProgress,
            'chief_complaint' => fake()->sentence(),
            'objective' => null,
            'signed_by_user_id' => null,
            'signed_at' => null,
            'completed_at' => null,
        ];
    }
}
