<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Database\Seeders\HipocratiqUatAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class ClinicalCodingTest extends TestCase
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
    }

    public function test_doctor_can_record_update_and_lock_structured_clinical_codings(): void
    {
        $doctor = User::query()->where('email', 'medico@hipocratiq.com')->firstOrFail();
        $patientUser = User::query()->where('email', 'paciente@hipocratiq.com')->firstOrFail();
        $patient = PatientProfile::query()->where('user_id', $patientUser->id)->firstOrFail();

        $appointment = Appointment::factory()->create([
            'clinic_id' => $patient->clinic_id,
            'patient_profile_id' => $patient->id,
            'doctor_user_id' => $doctor->id,
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->authenticateDoctor($doctor->email);

        $created = $this->withSpaOrigin()
            ->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
                'appointment_id' => $appointment->id,
                'chief_complaint' => 'Registro UAT de terminología clínica estructurada.',
                'codings' => [
                    [
                        'category' => 'diagnosis',
                        'system' => 'http://snomed.info/sct',
                        'code' => 'TEST-SCT-001',
                        'display' => 'Synthetic SNOMED concept',
                        'status' => 'active',
                    ],
                    [
                        'category' => 'diagnosis',
                        'system' => 'http://snomed.info/sct',
                        'code' => 'TEST-SCT-001',
                        'display' => 'Synthetic SNOMED concept',
                        'status' => 'active',
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.codings.0.system', 'http://snomed.info/sct');

        $consultationId = (int) $created->json('data.id');
        $this->assertDatabaseCount('clinical_codings', 1);

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/consultations/{$consultationId}", [
                'codings' => [[
                    'category' => 'diagnosis',
                    'system' => 'http://id.who.int/icd/release/11/mms',
                    'code' => 'TEST-ICD-001',
                    'display' => 'Synthetic ICD concept',
                    'status' => 'active',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.codings.0.system', 'http://id.who.int/icd/release/11/mms');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/consultations/{$consultationId}", ['status' => 'completed'])
            ->assertOk();

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/consultations/{$consultationId}", ['status' => 'signed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'signed');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/consultations/{$consultationId}", [
                'codings' => [[
                    'category' => 'diagnosis',
                    'system' => 'urn:hipocratiq:test',
                    'code' => 'AFTER-SIGN',
                    'display' => 'Must remain blocked',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    private function authenticateDoctor(string $email): void
    {
        $challenge = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/otp/request', [
                'purpose' => 'login',
                'client_type' => 'web',
                'actor_context' => 'staff',
                'requested_role' => 'doctor',
                'identifier' => $email,
                'password' => $this->password,
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
