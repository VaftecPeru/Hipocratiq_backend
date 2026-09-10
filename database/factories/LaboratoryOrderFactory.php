<?php

namespace Database\Factories;

use App\Enums\LaboratoryOrderStatus;
use App\Models\Clinic;
use App\Models\LaboratoryOrder;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<LaboratoryOrder> */
class LaboratoryOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'doctor_user_id' => User::factory(),
            'patient_profile_id' => PatientProfile::factory(),
            'consultation_id' => null,
            'study_name' => 'Hemograma completo',
            'status' => LaboratoryOrderStatus::Requested,
            'priority' => 'normal',
            'is_critical' => false,
            'ordered_at' => Carbon::now(),
            'result_at' => null,
            'result_summary' => null,
            'notes' => null,
        ];
    }
}
