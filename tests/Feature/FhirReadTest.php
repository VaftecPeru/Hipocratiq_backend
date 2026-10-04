<?php

namespace Tests\Feature;

use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Database\Seeders\HipocratiqUatAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class FhirReadTest extends TestCase
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

    public function test_metadata_exposes_published_fhir_r5_capabilities(): void
    {
        $this->getJson('/api/v1/fhir/metadata')
            ->assertOk()
            ->assertHeader('content-type', 'application/fhir+json; charset=UTF-8')
            ->assertJsonPath('resourceType', 'CapabilityStatement')
            ->assertJsonPath('fhirVersion', '5.0.0')
            ->assertJsonPath('rest.0.mode', 'server')
            ->assertJsonFragment(['type' => 'Patient'])
            ->assertJsonFragment(['type' => 'Encounter']);
    }

    public function test_patient_can_read_only_their_own_fhir_patient_resource(): void
    {
        $patientUser = User::query()->where('email', 'paciente@hipocratiq.com')->firstOrFail();
        $patient = PatientProfile::query()->where('user_id', $patientUser->id)->firstOrFail();

        $other = PatientProfile::factory()->create();

        $this->authenticatePatient($patientUser->email);

        $this->withSpaOrigin()
            ->getJson("/api/v1/fhir/Patient/{$patient->id}")
            ->assertOk()
            ->assertJsonPath('resourceType', 'Patient')
            ->assertJsonPath('id', (string) $patient->id)
            ->assertJsonPath('name.0.text', $patientUser->name);

        $this->withSpaOrigin()
            ->getJson("/api/v1/fhir/Patient/{$other->id}")
            ->assertNotFound();
    }

    private function authenticatePatient(string $email): void
    {
        $challenge = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/otp/request', [
                'purpose' => 'login',
                'client_type' => 'web',
                'actor_context' => 'patient',
                'requested_role' => 'patient',
                'identifier' => $email,
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
