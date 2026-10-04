<?php

namespace Tests\Feature;

use App\Models\PatientConsent;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Database\Seeders\HipocratiqUatAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class PatientConsentTest extends TestCase
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

    public function test_patient_can_grant_list_revoke_and_read_fhir_consent(): void
    {
        $patientUser = User::query()->where('email', 'paciente@hipocratiq.com')->firstOrFail();
        $this->authenticatePatient($patientUser->email);

        $created = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/patient/consents', [
                'scope' => 'ai_clinical_assistance',
                'policy_version' => '2026-09',
            ])
            ->assertCreated()
            ->assertJsonPath('data.scope', 'ai_clinical_assistance')
            ->assertJsonPath('data.status', 'granted');

        $consentId = (int) $created->json('data.id');

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/patient/consents', [
                'scope' => 'ai_clinical_assistance',
                'policy_version' => '2026-09',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $consentId);

        $this->assertDatabaseCount('patient_consents', 1);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/patient/consents')
            ->assertOk()
            ->assertJsonPath('data.0.id', $consentId);

        $this->withSpaOrigin()
            ->getJson("/api/v1/fhir/Consent/{$consentId}")
            ->assertOk()
            ->assertJsonPath('resourceType', 'Consent')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('category.0.text', 'ai_clinical_assistance');

        $this->withSpaOrigin()
            ->postJson("/api/v1/auth/patient/consents/{$consentId}/revoke")
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->withSpaOrigin()
            ->getJson("/api/v1/fhir/Consent/{$consentId}")
            ->assertOk()
            ->assertJsonPath('status', 'inactive');

        $consent = PatientConsent::query()->findOrFail($consentId);
        $this->assertNotNull($consent->revoked_at);
        $this->assertDatabaseHas('admin_audit_events', [
            'module' => 'clinical-consent',
            'action' => 'consent.granted',
            'resource_id' => (string) $consentId,
        ]);
        $this->assertDatabaseHas('admin_audit_events', [
            'module' => 'clinical-consent',
            'action' => 'consent.revoked',
            'resource_id' => (string) $consentId,
        ]);
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
