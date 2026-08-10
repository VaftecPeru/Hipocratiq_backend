<?php

namespace Tests\Feature;

use App\Models\AdminAuditEvent;
use App\Models\SupportTicket;
use App\Models\SystemIncident;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_notification_center_combines_real_operational_sources_and_tracks_reads(): void
    {
        $admin = $this->authenticateAdmin();
        $audit = AdminAuditEvent::query()->create([
            'actor_user_id' => $admin->id,
            'country_code' => 'MX',
            'module' => 'billing',
            'action' => 'payment_failed',
            'is_critical' => true,
            'resource_label' => 'Factura FAC-100',
            'occurred_at' => now()->subMinute(),
        ]);
        SystemIncident::query()->create([
            'title' => 'Intermitencia de caché',
            'description' => 'Se detectaron respuestas intermitentes en el servicio de caché.',
            'severity' => 'major',
            'status' => 'investigating',
            'service_key' => 'cache',
            'created_by_user_id' => $admin->id,
            'started_at' => now(),
        ]);
        SupportTicket::factory()->create(['due_at' => now()->subHour()]);

        $response = $this->withSpaOrigin()->getJson('/api/v1/auth/admin/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.unread_count', 3)
            ->assertJsonStructure(['data' => [['id', 'type', 'title', 'message', 'severity', 'href', 'occurred_at', 'read']]]);

        $this->withSpaOrigin()->postJson('/api/v1/auth/admin/notifications/read', [
            'notification_ids' => ["audit:{$audit->id}"],
        ])->assertOk()->assertJsonPath('data.marked_read', 1);

        $this->withSpaOrigin()->getJson('/api/v1/auth/admin/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 2)
            ->assertJsonFragment(['id' => "audit:{$audit->id}", 'read' => true]);

        $this->withSpaOrigin()->postJson('/api/v1/auth/admin/notifications/read-all')
            ->assertOk()->assertJsonPath('data.marked_read', 3);
        $this->assertDatabaseCount('admin_notification_reads', 3);
    }

    public function test_notification_read_rejects_invalid_identifiers(): void
    {
        $this->authenticateAdmin();

        $this->withSpaOrigin()->postJson('/api/v1/auth/admin/notifications/read', [
            'notification_ids' => ['../../../secret', 'unknown:1'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['notification_ids.0', 'notification_ids.1']);
    }

    private function authenticateAdmin(): User
    {
        $admin = User::factory()->superAdmin()->create([
            'email' => 'notifications-admin@example.test',
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

        return $admin;
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
