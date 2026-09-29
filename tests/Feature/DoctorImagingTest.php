<?php

namespace Tests\Feature;

use App\Enums\ImagingStudyStatus;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Database\Seeders\HipocratiqUatAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class DoctorImagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'uat.enabled' => true,
            'uat.password' => Str::random(32),
        ]);
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        $this->seed(HipocratiqUatAccessSeeder::class);
        $this->authenticateIndependentDoctor();
    }

    public function test_independent_doctor_can_order_and_progress_an_imaging_study(): void
    {
        $doctor = User::query()->where('email', 'medico.independiente@hipocratiq.com')->firstOrFail();
        $patientUser = User::factory()->create([
            'country_code' => 'MX',
            'phone_number' => '+525510008101',
        ]);
        $patient = PatientProfile::factory()->create([
            'user_id' => $patientUser->id,
            'clinic_id' => null,
            'owner_doctor_user_id' => $doctor->id,
        ]);

        $created = $this->withSpaOrigin()->postJson('/api/v1/auth/doctor/imaging', [
            'patient_profile_id' => $patient->id,
            'modality' => 'CT',
            'study_description' => 'TAC de tórax sin contraste',
            'notes' => 'Seguimiento diagnóstico.',
        ])->assertCreated()
            ->assertJsonPath('data.status', ImagingStudyStatus::Ordered->value)
            ->assertJsonPath('data.patient.id', $patient->id)
            ->assertJsonPath('data.modality', 'CT');

        $studyId = (int) $created->json('data.id');

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/imaging')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.ordered', 1)
            ->assertJsonPath('meta.connection.status', 'not_configured');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/imaging/{$studyId}/status", [
                'status' => ImagingStudyStatus::Available->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        foreach ([
            ImagingStudyStatus::Scheduled,
            ImagingStudyStatus::Available,
            ImagingStudyStatus::Reviewed,
        ] as $status) {
            $this->withSpaOrigin()
                ->patchJson("/api/v1/auth/doctor/imaging/{$studyId}/status", [
                    'status' => $status->value,
                ])
                ->assertOk()
                ->assertJsonPath('data.status', $status->value);
        }

        $this->assertDatabaseHas('imaging_studies', [
            'id' => $studyId,
            'doctor_user_id' => $doctor->id,
            'patient_profile_id' => $patient->id,
            'status' => ImagingStudyStatus::Reviewed->value,
        ]);
        $this->assertDatabaseHas('admin_audit_events', [
            'module' => 'clinical-imaging',
            'action' => 'imaging.study.ordered',
            'resource_id' => (string) $studyId,
        ]);
    }

    private function authenticateIndependentDoctor(): void
    {
        $challenge = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/otp/request', [
                'purpose' => 'login',
                'client_type' => 'web',
                'actor_context' => 'doctor',
                'requested_role' => 'doctor',
                'identifier' => 'medico.independiente@hipocratiq.com',
            ])
            ->assertAccepted();

        $verification = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/otp/verify', [
                'purpose' => 'login',
                'client_type' => 'web',
                'challenge_id' => $challenge->json('data.challenge_id'),
                'code' => '123456',
            ])
            ->assertOk();

        $cookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($cookie->getName(), $cookie->getValue());
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
