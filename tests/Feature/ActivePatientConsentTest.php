<?php

namespace Tests\Feature;

use App\Enums\PatientConsentScope;
use App\Enums\PatientConsentStatus;
use App\Models\Clinic;
use App\Models\PatientConsent;
use App\Models\PatientProfile;
use App\Models\User;
use App\Support\Clinical\ActivePatientConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivePatientConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_clinic_consent_is_not_reused_in_another_clinical_context(): void
    {
        config(['clinical.consent_policy_version' => '2026-09']);

        $patientClinic = Clinic::factory()->create();
        $foreignClinic = Clinic::factory()->create();
        $patient = $this->patient($patientClinic->id);

        $this->consent($patient, PatientConsentScope::Telemedicine, $foreignClinic->id);

        self::assertFalse(app(ActivePatientConsent::class)->allows(
            $patient,
            PatientConsentScope::Telemedicine,
        ));

        $this->consent($patient, PatientConsentScope::Telemedicine, $patientClinic->id);

        self::assertTrue(app(ActivePatientConsent::class)->allows(
            $patient,
            PatientConsentScope::Telemedicine,
        ));
    }

    public function test_independent_context_only_accepts_consent_without_clinic(): void
    {
        config(['clinical.consent_policy_version' => '2026-09']);

        $foreignClinic = Clinic::factory()->create();
        $patient = $this->patient(null);

        $this->consent($patient, PatientConsentScope::EpidemiologyAnonymized, $foreignClinic->id);

        $service = app(ActivePatientConsent::class);

        self::assertSame([], $service->patientIds(
            [$patient->id],
            PatientConsentScope::EpidemiologyAnonymized,
            null,
        )->all());

        $this->consent($patient, PatientConsentScope::EpidemiologyAnonymized, null);

        self::assertSame([$patient->id], $service->patientIds(
            [$patient->id],
            PatientConsentScope::EpidemiologyAnonymized,
            null,
        )->all());
    }

    private function patient(?int $clinicId): PatientProfile
    {
        $user = User::factory()->create();

        return $user->patientProfile()->create([
            'clinic_id' => $clinicId,
            'owner_doctor_user_id' => null,
        ]);
    }

    private function consent(
        PatientProfile $patient,
        PatientConsentScope $scope,
        ?int $clinicId,
    ): PatientConsent {
        return PatientConsent::query()->create([
            'patient_profile_id' => $patient->id,
            'clinic_id' => $clinicId,
            'granted_by_user_id' => $patient->user_id,
            'scope' => $scope,
            'policy_version' => (string) config('clinical.consent_policy_version'),
            'status' => PatientConsentStatus::Granted,
            'source' => 'test',
            'effective_from' => now()->subMinute(),
        ]);
    }
}
