<?php

namespace Tests\Feature;

use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\PatientProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class ClinicDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_clinic_admin_can_read_live_clinic_dashboard_members_patients_and_update_settings(): void
    {
        [$clinic, $adminRole] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador Clínico');
        $admin = User::factory()->create([
            'name' => 'Admin Clínica',
            'phone_number' => '+525510000001',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $admin, $adminRole);
        $patient = User::factory()->create(['name' => 'Paciente Real']);
        PatientProfile::factory()->create(['user_id' => $patient->id, 'clinic_id' => $clinic->id]);

        $this->authenticate($admin, '55 1000 0001');

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/dashboard')
            ->assertOk()
            ->assertJsonPath('data.clinic.id', $clinic->id)
            ->assertJsonPath('data.metrics.patients_total', 1)
            ->assertJsonPath('data.team.0.role', 'clinic_admin');

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/patients')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Paciente Real')
            ->assertJsonPath('meta.total', 1);

        $this->withSpaOrigin()
            ->patchJson('/api/v1/auth/clinic/settings', [
                'name' => 'Clínica Actualizada',
                'address' => 'Av. Nueva 123',
                'contact_phone' => '55 5555 5555',
            ])
            ->assertOk()
            ->assertJsonPath('data.clinic.name', 'Clínica Actualizada');

        $this->assertDatabaseHas('clinics', [
            'id' => $clinic->id,
            'name' => 'Clínica Actualizada',
            'address' => 'Av. Nueva 123',
        ]);
    }

    public function test_receptionist_can_access_clinic_dashboard_but_cannot_update_clinic_settings(): void
    {
        [$clinic, $receptionistRole] = $this->createClinicRole(RoleKey::Receptionist, 'Recepcionista');
        $receptionist = User::factory()->create([
            'name' => 'Recepción Clínica',
            'phone_number' => '+525510000002',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $receptionist, $receptionistRole);

        $this->authenticate($receptionist, '55 1000 0002');

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/dashboard')
            ->assertOk()
            ->assertJsonPath('data.team.0.role', 'receptionist');

        $this->withSpaOrigin()
            ->patchJson('/api/v1/auth/clinic/settings', ['name' => 'No autorizado'])
            ->assertForbidden()
            ->assertJsonPath('code', 'ACTOR_CONTEXT_FORBIDDEN');
    }

    public function test_clinic_admin_can_create_a_receptionist_inside_their_clinic(): void
    {
        [$clinic, $adminRole] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador ClÃ­nico');
        $receptionistRole = Role::query()->create([
            'key' => RoleKey::Receptionist->value,
            'name' => 'Recepcionista',
            'scope' => RoleScope::Organization,
            'is_system' => true,
        ]);
        $admin = User::factory()->create([
            'phone_number' => '+525510000003',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $admin, $adminRole);
        $this->authenticate($admin, '55 1000 0003');

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/members', [
                'name' => 'Recepcionista de Clínica',
                'email' => 'recepcionista@clinic.test',
                'phone_number' => '55 1000 0004',
                'country_code' => 'MX',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Recepcionista de Clínica')
            ->assertJsonPath('data.role', 'receptionist')
            ->assertJsonPath('meta.requires_password_reset', true);

        $user = User::query()->where('email', 'recepcionista@clinic.test')->firstOrFail();
        $this->assertDatabaseHas('role_user', ['user_id' => $user->id, 'role_id' => $receptionistRole->id]);
        $this->assertDatabaseHas('clinic_memberships', [
            'user_id' => $user->id,
            'clinic_id' => $clinic->id,
            'role_id' => $receptionistRole->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_user_id' => $admin->id,
            'clinic_id' => $clinic->id,
            'module' => 'clinic_team',
            'action' => 'receptionist_created',
            'resource_id' => (string) $user->id,
        ]);
    }

    /** @return array{0: Clinic, 1: Role} */
    private function createClinicRole(RoleKey $roleKey, string $name): array
    {
        $clinic = Clinic::factory()->create();
        ClinicSubscription::factory()->create(['clinic_id' => $clinic->id]);
        $role = Role::query()->create([
            'key' => $roleKey->value,
            'name' => $name,
            'scope' => RoleScope::Organization,
            'is_system' => true,
        ]);

        return [$clinic, $role];
    }

    private function attachMembership(Clinic $clinic, User $user, Role $role): void
    {
        $user->roles()->sync([$role->id]);
        ClinicMembership::query()->create([
            'clinic_id' => $clinic->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    private function authenticate(User $user, string $phone): void
    {
        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => $phone,
            'password' => 'SecurePass123',
        ])->assertAccepted();
        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ])->assertOk()->assertJsonPath('data.actor_context', $user->roles()->first()->key);
        $sessionCookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue());
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
