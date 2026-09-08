<?php

namespace Tests\Feature;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Models\Clinic;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketCreatedNotification;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminSupportTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_super_admin_can_create_a_ticket_with_a_generated_ticket_id(): void
    {
        $admin = $this->authenticateAdmin();
        $clinic = Clinic::factory()->create(['name' => 'Clínica San José']);

        $response = $this->withSpaOrigin()->postJson('/api/v1/auth/admin/support-tickets', [
            'clinic_id' => $clinic->id,
            'category' => SupportTicketCategory::Technical->value,
            'priority' => SupportTicketPriority::High->value,
            'subject' => 'No puedo generar el reporte mensual',
            'description' => 'El reporte se queda cargando después de seleccionar el periodo.',
            'contact_email' => 'soporte@clinica.test',
            'notify_on_create' => true,
            'assign_to_self' => true,
        ])->assertCreated();

        $ticketId = $response->json('data.ticket_id');

        $this->assertIsString($ticketId);
        $this->assertMatchesRegularExpression('/^TK-\d{6}$/', $ticketId);
        $response
            ->assertJsonPath('data.clinic.id', $clinic->id)
            ->assertJsonPath('data.clinic.name', 'Clínica San José')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.assigned_to.id', $admin->id);

        $this->assertDatabaseHas('support_tickets', [
            'ticket_id' => $ticketId,
            'clinic_id' => $clinic->id,
            'requested_by_user_id' => $admin->id,
            'assigned_to_user_id' => $admin->id,
        ]);
    }

    public function test_super_admin_can_list_tickets_and_filter_by_ticket_id(): void
    {
        $this->authenticateAdmin();
        $clinic = Clinic::factory()->create();
        $ticket = SupportTicket::factory()->create([
            'clinic_id' => $clinic->id,
            'subject' => 'Error de acceso al sistema',
        ]);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/support-tickets?search='.urlencode($ticket->ticket_id).'&per_page=5')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ticket_id', $ticket->ticket_id)
            ->assertJsonPath('data.0.clinic.id', $clinic->id)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('filter_options.clinics.0.id', $clinic->id);
    }

    public function test_ticket_creation_requires_valid_input(): void
    {
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/admin/support-tickets', [
                'clinic_id' => 999999,
                'category' => 'unknown',
                'priority' => 'unknown',
                'subject' => '',
                'description' => '',
                'contact_email' => 'not-an-email',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'clinic_id',
                'category',
                'priority',
                'subject',
                'description',
                'contact_email',
            ]);
    }

    public function test_ticket_creation_records_sla_activity_and_sends_requested_notification(): void
    {
        Notification::fake();
        $this->authenticateAdmin();
        $clinic = Clinic::factory()->create(['country_code' => 'PE']);

        $response = $this->withSpaOrigin()->postJson('/api/v1/auth/admin/support-tickets', [
            'clinic_id' => $clinic->id,
            'category' => SupportTicketCategory::Billing->value,
            'priority' => SupportTicketPriority::Urgent->value,
            'subject' => 'Cobro duplicado',
            'description' => 'La clínica reporta dos cargos para el mismo periodo.',
            'contact_email' => 'finanzas@clinica.test',
            'notify_on_create' => true,
            'assign_to_self' => false,
        ])->assertCreated();

        $ticket = SupportTicket::query()->findOrFail($response->json('data.id'));

        $this->assertNotNull($ticket->due_at);
        $this->assertEquals(2, $ticket->created_at->diffInHours($ticket->due_at));
        $this->assertDatabaseHas('support_ticket_activities', [
            'support_ticket_id' => $ticket->id,
            'type' => 'created',
        ]);
        Notification::assertSentOnDemand(SupportTicketCreatedNotification::class);
    }

    public function test_super_admin_can_manage_ticket_workflow_and_add_internal_comments(): void
    {
        $admin = $this->authenticateAdmin();
        $ticket = SupportTicket::factory()->create([
            'priority' => SupportTicketPriority::Normal->value,
            'status' => SupportTicketStatus::Open->value,
        ]);

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/admin/support-tickets/{$ticket->id}", [
                'status' => SupportTicketStatus::InProgress->value,
                'priority' => SupportTicketPriority::High->value,
                'assigned_to_user_id' => $admin->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.assigned_to.id', $admin->id);

        $this->withSpaOrigin()
            ->postJson("/api/v1/auth/admin/support-tickets/{$ticket->id}/comments", [
                'body' => 'Se solicitó evidencia adicional a la clínica.',
                'is_internal' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'comment')
            ->assertJsonPath('data.actor.id', $admin->id);

        $this->withSpaOrigin()
            ->getJson("/api/v1/auth/admin/support-tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonCount(4, 'data.activities');
    }

    public function test_dashboard_summary_is_global_and_groups_tickets_by_country(): void
    {
        $this->authenticateAdmin();
        $mexico = Clinic::factory()->create(['country_code' => 'MX']);
        $peru = Clinic::factory()->create(['country_code' => 'PE']);
        SupportTicket::factory()->create([
            'clinic_id' => $mexico->id,
            'status' => SupportTicketStatus::Open->value,
            'due_at' => now()->subHour(),
        ]);
        SupportTicket::factory()->create([
            'clinic_id' => $peru->id,
            'status' => SupportTicketStatus::Resolved->value,
            'due_at' => now()->addHour(),
            'resolved_at' => now(),
        ]);

        $response = $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/support-tickets?per_page=10')
            ->assertOk()
            ->assertJsonPath('summary.totals.tickets', 2)
            ->assertJsonPath('summary.totals.overdue', 1)
            ->assertJsonCount(2, 'summary.by_country');

        $this->assertEqualsCanonicalizing(
            ['MX', 'PE'],
            collect($response->json('summary.by_country'))->pluck('country_code')->all(),
        );
    }

    private function authenticateAdmin(): User
    {
        $admin = User::factory()->superAdmin()->create([
            'email' => 'admin@example.test',
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
