<?php

namespace Database\Factories;

use App\Models\PatientIntakeDraft;
use App\Models\PatientProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientIntakeDraft>
 */
class PatientIntakeDraftFactory extends Factory
{
    protected $model = PatientIntakeDraft::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_profile_id' => PatientProfile::factory(),
            'clinic_id' => null,
            'sections' => [
                'patient_identification' => [
                    'name' => fake()->name(),
                    'age' => fake()->numberBetween(18, 85),
                    'document_type' => 'DNI',
                    'document_number' => fake()->numerify('########'),
                    'birth_date' => fake()->date(),
                    'gender' => 'Otro',
                    'occupation' => fake()->jobTitle(),
                    'address' => fake()->address(),
                    'guardian_name' => null,
                    'relationship' => null,
                    'phone_number' => fake()->phoneNumber(),
                    'email' => fake()->safeEmail(),
                    'emergency_contact' => null,
                    'insurance' => null,
                ],
                'family_history' => ['description' => null],
                'non_pathological_history' => ['description' => null],
                'pathological_history' => ['description' => null],
                'gyneco_obstetric_history' => ['description' => null],
                'present_illness' => ['reason' => null, 'illness_narrative' => null],
                'systems_review' => ['description' => null],
                'vital_signs' => [
                    'blood_pressure_systolic' => null,
                    'blood_pressure_diastolic' => null,
                    'temperature_c' => null,
                    'heart_rate' => null,
                    'respiratory_rate' => null,
                    'oxygen_saturation' => null,
                    'weight_kg' => null,
                    'height_cm' => null,
                    'notes' => null,
                ],
            ],
            'declaration_accepted_at' => null,
        ];
    }
}
