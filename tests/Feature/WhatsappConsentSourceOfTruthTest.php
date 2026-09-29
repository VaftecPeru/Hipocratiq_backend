<?php

namespace Tests\Feature;

use App\Enums\PatientConsentScope;
use App\Models\PatientConsent;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Database\Seeders\HipocratiqUatAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class WhatsappConsentSourceOfTruthTest extends TestCase
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
        $this->authenticatePatient();
    }

    public function test_profile_update_cannot_mutate_legacy_whatsapp_consent(): void
    {
        $user = User::query()->where('email', 'paciente@hipocratiq.com')->firstOrFail();
        $patient = PatientProfile::query()->where('user_id', $user->id)->firstOrFail();
        $legacyValue = (bool) $patient->whatsapp_consent;

        $this->withSpaOrigin()
            ->patchJson('/api/v1/auth/patient/profile', [
                'whatsapp_consent' => ! $legacyValue,
                'address' => 'Dirección actualizada sin tocar consentimiento',
            ])
            ->assertOk();

        $this->assertSame(
            $legacyValue,
            (bool) $patient->fresh()->whatsapp_consent,
        );
    }

    public function test_versioned_whatsapp_scope_is_the_operational_consent(): void
    {
        $created = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/patient/consents', [
                'scope' => PatientConsentScope::WhatsAppNotifications->value,
                'policy_version' => (string) config('clinical.consent_policy_version'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.scope', PatientConsentScope::WhatsAppNotifications->value)
            ->assertJsonPath('data.status', 'granted');

        $this->assertDatabaseHas('patient_consents', [
            'id' => (int) $created->json('data.id'),
            'scope' => PatientConsentScope::WhatsAppNotifications->value,
            'status' => 'granted',
        ]);

        $consent = PatientConsent::query()->findOrFail((int) $created->json('data.id'));
        $this->assertNull($consent->revoked_at);
    }

    private function authenticatePatient(): void
    {
        $challenge = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/otp/request', [
                'purpose' => 'login',
                'client_type' => 'web',
                'actor_context' => 'patient',
                'requested_role' => 'patient',
                'identifier' => 'paciente@hipocratiq.com',
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
