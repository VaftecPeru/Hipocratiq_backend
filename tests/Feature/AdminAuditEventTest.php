<?php

namespace Tests\Feature;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketPriority;
use App\Models\AuthenticationEvent;
use App\Models\Clinic;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminAuditEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_global_audit_combines_admin_and_authentication_events(): void
    {
        $admin = $this->authenticateAdmin();
        $clinic = Clinic::factory()->create(['country_code' => 'PE']);

        $this->withSpaOrigin()->postJson('/api/v1/auth/admin/support-tickets', [
            'clinic_id' => $clinic->id,
            'category' => SupportTicketCategory::Technical->value,
            'priority' => SupportTicketPriority::High->value,
            'subject' => 'Intermitencia regional',
            'description' => 'Se requiere seguimiento del servicio en Perú.',
            'contact_email' => 'soporte@clinica.test',
            'notify_on_create' => false,
            'assign_to_self' => true,
        ])->assertCreated();

        AuthenticationEvent::create([
            'user_id' => $admin->id,
            'event' => 'login_failed',
            'outcome' => 'failure',
            'ip_address' => '192.0.2.10',
            'metadata' => [],
            'occurred_at' => now(),
        ]);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/audit-events?module=support&country_code=PE')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.module', 'support')
            ->assertJsonPath('data.0.action', 'ticket_created')
            ->assertJsonPath('data.0.country_code', 'PE')
            ->assertJsonPath('data.0.actor.id', $admin->id)
            ->assertJsonPath('summary.totals.countries', 1);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/audit-events?critical=1')
            ->assertOk()
            ->assertJsonFragment([
                'module' => 'authentication',
                'action' => 'login_failed',
                'is_critical' => true,
            ]);
    }

    public function test_audit_route_rejects_requests_without_an_active_admin_session(): void
    {
        $this->actingAs(User::factory()->create());

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/audit-events')
            ->assertUnauthorized();
    }

    private function authenticateAdmin(): User
    {
        $admin = User::factory()->superAdmin()->create([
            'email' => 'audit-admin@example.test',
            'phone_number' => '+525512345678',
            'password' => 'SecurePass123',
        ]);

        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => '55 1234 5678',
            'password' => 'SecurePass123',
            'requested_role' => 'super_admin',
        ])->assertAccepted();
        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ])->assertOk();
        $sessionCookie = $verification->getCookie(config('session.cookie'));

        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue());

        return $admin;
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
