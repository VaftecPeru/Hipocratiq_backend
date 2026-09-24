<?php

namespace Tests\Feature;

use App\Models\ClinicalHistorySection;
use App\Models\Consultation;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Database\Seeders\ClinicalHistorySectionSeeder;
use Database\Seeders\HipocratiqUatAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class Phase4ClinicalUatTest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $this->password = Str::random(32);
        config([
            'uat.enabled' => true,
            'uat.password' => $this->password,
        ]);
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        $this->seed(HipocratiqUatAccessSeeder::class);
        $this->seed(ClinicalHistorySectionSeeder::class);
    }

    public function test_official_uat_journey_from_clinic_appointment_to_signed_history_and_patient_portal(): void
    {
        $patientUser = User::query()->where('email', 'paciente@hipocratiq.com')->firstOrFail();
        $patient = PatientProfile::query()->where('user_id', $patientUser->id)->firstOrFail();
        $doctor = User::query()->where('email', 'medico@hipocratiq.com')->firstOrFail();

        $this->authenticateWeb('admin@hipocratiq.com', 'clinic_admin', 'staff');

        $appointment = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/appointments', [
                'patient_profile_id' => $patient->id,
                'doctor_user_id' => $doctor->id,
                'scheduled_at' => now()->addWeek()->startOfWeek()->setTime(10, 0)->toIso8601String(),
                'appointment_type' => 'Consulta general UAT',
            ])
            ->assertCreated()
            ->assertJsonPath('data.patient.id', $patient->id)
            ->assertJsonPath('data.doctor.id', $doctor->id);

        $appointmentId = (int) $appointment->json('data.id');

        $this->resetSession();
        $this->authenticateWeb('medico@hipocratiq.com', 'doctor', 'staff');

        $consultation = $this->withSpaOrigin()
            ->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
                'appointment_id' => $appointmentId,
                'chief_complaint' => 'Paciente acude a consulta UAT para evaluación clínica integral.',
                'objective' => 'Registrar historia clínica general y confirmar trazabilidad de la atención.',
                'sections' => $this->clinicalSectionsExceptClinician(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.patient.id', $patient->id)
            ->assertJsonPath('data.appointment_id', $appointmentId)
            ->assertJsonPath('data.status', 'in_progress');

        $consultationId = (int) $consultation->json('data.id');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/consultations/{$consultationId}", [
                'status' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $signed = $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/consultations/{$consultationId}", [
                'status' => 'signed',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'signed')
            ->assertJsonPath('data.signature.doctor_name', 'Oscar Gutiérrez Rico')
            ->assertJsonPath('data.signature.medical_license', '90000001');

        $this->assertNotEmpty($signed->json('data.signature.integrity_sha256'));
        $this->assertDatabaseHas('consultations', [
            'id' => $consultationId,
            'status' => 'signed',
            'signed_doctor_name' => 'Oscar Gutiérrez Rico',
            'signed_doctor_medical_license' => '90000001',
        ]);
        $this->assertDatabaseCount('clinical_history_entries', 15);
        $this->assertNotNull(Consultation::query()->findOrFail($consultationId)->signature_digest);

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/consultations/{$consultationId}", [
                'chief_complaint' => 'Intento de alterar una nota firmada.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->resetSession();
        $this->authenticateWeb('paciente@hipocratiq.com', 'patient', 'patient');

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/patient/appointments')
            ->assertOk()
            ->assertJsonFragment(['id' => $appointmentId]);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/patient/history')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $consultationId)
            ->assertJsonPath('data.0.status', 'signed')
            ->assertJsonPath('data.0.doctor.name', 'Oscar Gutiérrez Rico');
    }

    /** @return array<string, array<string, string>> */
    private function clinicalSectionsExceptClinician(): array
    {
        $payload = [];

        foreach (ClinicalHistorySection::query()
            ->where('is_active', true)
            ->where('key', '<>', 'clinician_identification')
            ->orderBy('sort_order')
            ->get() as $section) {
            $payload[$section->key] = ['description' => 'UAT '.$section->title];
        }

        return $payload;
    }

    private function authenticateWeb(string $email, string $role, string $actorContext): void
    {
        $payload = [
            'purpose' => 'login',
            'client_type' => 'web',
            'actor_context' => $actorContext,
            'requested_role' => $role,
            'identifier' => $email,
        ];

        if ($actorContext === 'staff') {
            $payload['password'] = $this->password;
        }

        $challenge = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/otp/request', $payload)
            ->assertAccepted();

        $verification = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/otp/verify', [
                'purpose' => 'login',
                'client_type' => 'web',
                'challenge_id' => $challenge->json('data.challenge_id'),
                'code' => '123456',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.email', $email);

        $cookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($cookie->getName(), $cookie->getValue());
    }

    private function resetSession(): void
    {
        $this->defaultCookies = [];
        $this->actingAsGuest();
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
