<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use App\Support\Clinical\EmergencyPolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminEmergencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_super_admin_can_update_and_publish_emergency_policy(): void
    {
        $this->authenticateAdmin();
        Clinic::factory()->create(['country_code' => 'PE']);

        $this->withSpaOrigin()->patchJson('/api/v1/auth/admin/emergency', [
            'enabled' => true,
            'visibility' => 'doctors_admins',
            'color' => '#EF2F3F',
            'inheritance' => 'responsibles_only',
            'default_channel' => 'priority_push',
            'escalation_minutes' => 5,
            'responsibles' => [['role' => 'Jefe de guardia', 'channel' => 'Push prioritario']],
        ])->assertOk()->assertJsonPath('data.policy.escalation_minutes', 5);

        $this->withSpaOrigin()->postJson('/api/v1/auth/admin/emergency/publish', ['scope' => 'PE', 'force' => false, 'note' => 'Versión regional'])
            ->assertUnprocessable();

        $this->withSpaOrigin()->postJson('/api/v1/auth/admin/emergency/publish', ['scope' => 'all', 'force' => false, 'note' => 'Versión global'])
            ->assertOk()
            ->assertJsonPath('data.affected_clinics', 1)
            ->assertJsonPath('data.publication.scope', 'all')
            ->assertJsonPath('data.publication.force', true);

        $publication = PlatformSetting::query()->findOrFail(1)->emergency_publication;
        self::assertSame('all', $publication['scope']);
        self::assertSame('Versión global', $publication['note']);
        $this->assertDatabaseHas('admin_audit_events', ['module' => 'emergency', 'action' => 'emergency_policy_published', 'is_critical' => true]);
    }

    public function test_emergency_get_does_not_create_platform_settings(): void
    {
        $this->authenticateAdmin();
        PlatformSetting::query()->delete();

        $this->withSpaOrigin()->getJson('/api/v1/auth/admin/emergency')->assertOk();

        $this->assertDatabaseCount('platform_settings', 0);
    }

    public function test_global_publication_overrides_local_clinic_policy(): void
    {
        $this->authenticateAdmin();
        $clinic = Clinic::factory()->create([
            'preferences' => [
                'emergency' => [
                    'enabled' => false,
                    'label' => 'Alerta local',
                    'responsibles' => [['role' => 'Recepción local', 'channel' => 'Alerta interna']],
                ],
            ],
        ]);

        $this->withSpaOrigin()->patchJson('/api/v1/auth/admin/emergency', [
            'enabled' => true,
            'visibility' => 'reception_doctors_admins',
            'label' => 'Emergencia global',
            'color' => '#EF2F3F',
            'inheritance' => 'workflow_and_responsibles',
            'default_channel' => 'priority_push',
            'escalation_minutes' => 3,
            'responsibles' => [['role' => 'Jefe global', 'channel' => 'Push prioritario']],
        ])->assertOk();

        $this->withSpaOrigin()->postJson('/api/v1/auth/admin/emergency/publish', [
            'scope' => 'all',
            'note' => 'Override obligatorio',
        ])->assertOk();

        $resolved = app(EmergencyPolicyResolver::class)->resolve($clinic->fresh());

        self::assertFalse($resolved['local']['enabled']);
        self::assertTrue($resolved['effective']['enabled']);
        self::assertTrue($resolved['is_overridden']);
        self::assertSame('Jefe global', $resolved['effective']['responsibles'][0]['role']);
    }

    private function authenticateAdmin(): void
    {
        User::factory()->superAdmin()->create(['email' => 'emergency-admin@example.test', 'phone_number' => '+525512345678', 'password' => 'SecurePass123']);
        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', ['country_code' => 'MX', 'phone_number' => '55 1234 5678', 'password' => 'SecurePass123', 'requested_role' => 'super_admin'])->assertAccepted();
        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', ['challenge_id' => $login->json('data.challenge_id'), 'code' => '123456'])->assertOk();
        $cookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($cookie->getName(), $cookie->getValue());
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
