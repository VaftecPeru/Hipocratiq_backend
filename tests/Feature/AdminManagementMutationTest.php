<?php

namespace Tests\Feature;

use App\Enums\ClinicStatus;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Enums\UserStatus;
use App\Models\Clinic;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AdminUserMessageNotification;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminManagementMutationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_super_admin_can_update_and_suspend_a_clinic_with_audit(): void
    {
        $admin = $this->authenticateAdmin();
        $clinic = Clinic::factory()->create(['country_code' => 'PE']);

        $this->withSpaOrigin()->patchJson("/api/v1/auth/admin/clinics/{$clinic->id}", [
            'name' => 'Clínica Lima Centro',
            'address' => 'Av. Central 100, Lima',
            'status' => ClinicStatus::Suspended->value,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Clínica Lima Centro')
            ->assertJsonPath('data.status', 'suspended');

        $this->assertDatabaseHas('admin_audit_events', [
            'actor_user_id' => $admin->id,
            'clinic_id' => $clinic->id,
            'module' => 'clinics',
            'action' => 'clinic_updated',
            'is_critical' => true,
        ]);
    }

    public function test_super_admin_can_create_a_clinic_with_an_active_subscription(): void
    {
        $admin = $this->authenticateAdmin();
        $plan = Plan::factory()->create(['key' => 'professional', 'is_active' => true]);

        $this->withSpaOrigin()->postJson('/api/v1/auth/admin/clinics', [
            'name' => 'Clínica Nueva Vida',
            'tax_id' => 'MX-NV-2026',
            'country_code' => 'MX',
            'contact_phone' => '55 1234 5678',
            'address' => 'Av. Reforma 120, CDMX',
            'plan' => $plan->key,
            'billing_cycle' => 'monthly',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Clínica Nueva Vida')
            ->assertJsonPath('data.plan', 'professional')
            ->assertJsonPath('data.status', 'active');

        $clinic = Clinic::query()->where('tax_id', 'MX-NV-2026')->firstOrFail();
        $this->assertDatabaseHas('clinic_subscriptions', [
            'clinic_id' => $clinic->id,
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'status' => 'active',
            'provider' => 'admin_manual',
        ]);
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_user_id' => $admin->id,
            'module' => 'clinics',
            'action' => 'clinic_created',
            'resource_id' => (string) $clinic->id,
            'is_critical' => true,
        ]);
    }

    public function test_admin_clinic_creation_validates_phone_and_tax_id(): void
    {
        $this->authenticateAdmin();
        $plan = Plan::factory()->create(['key' => 'basic', 'is_active' => true]);
        Clinic::factory()->create(['tax_id' => 'PE-DUPLICATE']);

        $this->withSpaOrigin()->postJson('/api/v1/auth/admin/clinics', [
            'name' => 'Clínica Duplicada',
            'tax_id' => 'pe-duplicate',
            'country_code' => 'PE',
            'contact_phone' => '123',
            'address' => 'Lima',
            'plan' => $plan->key,
            'billing_cycle' => 'annual',
        ])->assertUnprocessable()->assertJsonValidationErrors('contact_phone');
    }

    public function test_super_admin_can_update_user_and_disabling_revokes_sessions(): void
    {
        $admin = $this->authenticateAdmin();
        $user = User::factory()->create(['status' => UserStatus::Active->value]);

        $this->withSpaOrigin()->patchJson("/api/v1/auth/admin/users/{$user->id}", [
            'name' => 'Usuario Supervisado',
            'status' => UserStatus::Disabled->value,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Usuario Supervisado')
            ->assertJsonPath('data.status', 'disabled');

        $this->assertDatabaseHas('admin_audit_events', [
            'actor_user_id' => $admin->id,
            'module' => 'users',
            'action' => 'user_updated',
            'resource_id' => (string) $user->id,
        ]);
    }

    public function test_super_admin_can_create_a_clinic_administrator(): void
    {
        $admin = $this->authenticateAdmin();
        $clinic = Clinic::factory()->create();
        $clinicAdminRole = Role::query()->create([
            'key' => RoleKey::ClinicAdmin->value,
            'name' => 'Administrador Clínico',
            'scope' => RoleScope::Organization,
            'is_system' => true,
        ]);

        $this->withSpaOrigin()->postJson('/api/v1/auth/admin/users', [
            'name' => 'Administradora Nueva',
            'email' => 'new-admin@example.test',
            'phone_number' => '55 8765 4321',
            'country_code' => 'MX',
            'role' => 'clinic_admin',
            'clinic_id' => $clinic->id,
        ])->assertCreated()
            ->assertJsonPath('data.role', 'clinic_admin')
            ->assertJsonPath('data.clinic.id', $clinic->id)
            ->assertJsonPath('meta.requires_password_reset', true);

        $user = User::query()->where('email', 'new-admin@example.test')->firstOrFail();
        $this->assertDatabaseHas('role_user', ['user_id' => $user->id, 'role_id' => $clinicAdminRole->id]);
        $this->assertDatabaseHas('clinic_memberships', [
            'user_id' => $user->id,
            'clinic_id' => $clinic->id,
            'role_id' => $clinicAdminRole->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_user_id' => $admin->id,
            'module' => 'users',
            'action' => 'user_created',
            'resource_id' => (string) $user->id,
        ]);
    }

    public function test_admin_user_creation_rejects_specialized_roles(): void
    {
        $this->authenticateAdmin();
        $clinic = Clinic::factory()->create();

        $this->withSpaOrigin()->postJson('/api/v1/auth/admin/users', [
            'name' => 'Doctor sin registro',
            'email' => 'doctor-without-profile@example.test',
            'phone_number' => '55 4444 3333',
            'country_code' => 'MX',
            'role' => 'doctor',
            'clinic_id' => $clinic->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_super_admin_cannot_disable_own_account(): void
    {
        $admin = $this->authenticateAdmin();

        $this->withSpaOrigin()->patchJson("/api/v1/auth/admin/users/{$admin->id}", [
            'status' => UserStatus::Disabled->value,
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_super_admin_can_send_an_audited_message_to_a_user(): void
    {
        Notification::fake();
        $admin = $this->authenticateAdmin();
        $user = User::factory()->create(['email' => 'recipient@example.test']);

        $this->withSpaOrigin()->postJson("/api/v1/auth/admin/users/{$user->id}/messages", [
            'subject' => 'Seguimiento de soporte',
            'body' => 'Tu solicitud fue revisada por nuestro equipo.',
            'send_copy' => true,
        ])->assertAccepted();

        Notification::assertSentTo($user, AdminUserMessageNotification::class);
        Notification::assertSentTo($admin, AdminUserMessageNotification::class);
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_user_id' => $admin->id,
            'module' => 'users',
            'action' => 'user_message_sent',
            'resource_id' => (string) $user->id,
        ]);
    }

    private function authenticateAdmin(): User
    {
        $admin = User::factory()->superAdmin()->create([
            'email' => 'management-admin@example.test',
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
