<?php

namespace Tests\Feature;

use App\Enums\ClinicStatus;
use App\Enums\UserStatus;
use App\Models\AdminAuditEvent;
use App\Models\Clinic;
use App\Models\PatientProfile;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_super_admin_dashboard_returns_real_global_aggregates(): void
    {
        $this->authenticateAdmin();
        Clinic::factory()->create(['country_code' => 'PE', 'status' => ClinicStatus::Active->value]);
        Clinic::factory()->create(['country_code' => 'MX', 'status' => ClinicStatus::Suspended->value]);
        $patient = User::factory()->create(['country_code' => 'PE', 'status' => UserStatus::Active->value]);
        PatientProfile::factory()->create(['user_id' => $patient->id]);
        SubscriptionPayment::factory()->create(['amount_cents' => 12500, 'paid_at' => now()]);

        $this->withSpaOrigin()->getJson('/api/v1/auth/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kpis.active_clinics', 2)
            ->assertJsonPath('data.kpis.active_patients', 1)
            ->assertJsonPath('data.kpis.monthly_revenue_cents', 12500)
            ->assertJsonCount(12, 'data.revenue')
            ->assertJsonFragment(['country_code' => 'PE']);
    }

    public function test_super_admin_monitoring_returns_live_dependency_checks(): void
    {
        $this->authenticateAdmin();

        $this->withSpaOrigin()->getJson('/api/v1/auth/admin/monitoring')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'generated_at',
                    'overall_status',
                    'metrics' => ['active_sessions', 'pending_jobs', 'failed_jobs_24h'],
                    'services' => [['key', 'label', 'status', 'latency_ms', 'detail']],
                    'countries',
                    'operational_signals',
                    'event_volume',
                    'incidents',
                ],
            ])
            ->assertJsonFragment(['key' => 'database', 'status' => 'operational']);
    }

    public function test_super_admin_can_manage_an_incident_from_detection_to_resolution(): void
    {
        $this->authenticateAdmin();

        $creation = $this->withSpaOrigin()->postJson('/api/v1/auth/admin/system-incidents', [
            'title' => 'Latencia elevada en base de datos',
            'description' => 'Las consultas administrativas superan el umbral operativo esperado.',
            'severity' => 'major',
            'service_key' => 'database',
            'country_code' => 'MX',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'investigating')
            ->assertJsonPath('data.severity', 'major');
        $incidentId = $creation->json('data.id');

        $this->withSpaOrigin()->getJson('/api/v1/auth/admin/monitoring')
            ->assertOk()
            ->assertJsonPath('data.overall_status', 'degraded')
            ->assertJsonPath('data.metrics.open_incidents', 1)
            ->assertJsonPath('data.incidents.0.id', $incidentId);

        foreach (['identified', 'monitoring', 'resolved'] as $status) {
            $this->withSpaOrigin()->patchJson("/api/v1/auth/admin/system-incidents/{$incidentId}", [
                'status' => $status,
                'note' => "Transición a {$status}",
            ])->assertOk()->assertJsonPath('data.status', $status);
        }

        $this->assertDatabaseHas('system_incidents', [
            'id' => $incidentId,
            'status' => 'resolved',
        ]);
        $this->assertNotNull(AdminAuditEvent::query()->where('action', 'incident_resolved')->first());
        $this->withSpaOrigin()->getJson('/api/v1/auth/admin/monitoring')
            ->assertOk()
            ->assertJsonPath('data.metrics.open_incidents', 0);
    }

    public function test_incident_workflow_rejects_invalid_transitions(): void
    {
        $this->authenticateAdmin();
        $incidentId = $this->withSpaOrigin()->postJson('/api/v1/auth/admin/system-incidents', [
            'title' => 'Incidente de cola',
            'description' => 'Se detectaron trabajos retenidos que requieren investigación.',
            'severity' => 'minor',
            'service_key' => 'queue',
        ])->assertCreated()->json('data.id');

        $this->withSpaOrigin()->patchJson("/api/v1/auth/admin/system-incidents/{$incidentId}", [
            'status' => 'monitoring',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    private function authenticateAdmin(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'dashboard-admin@example.test',
            'phone_number' => '+525512345678',
            'password' => 'SecurePass123',
        ]);
        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => '55 1234 5678',
            'password' => 'SecurePass123',
        ])->assertAccepted();
        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ])->assertOk();
        $sessionCookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue());
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
