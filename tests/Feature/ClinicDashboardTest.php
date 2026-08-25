<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\PatientProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use App\Support\Authorization\RolePermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
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

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/patients', [
                'name' => 'Paciente del Administrador',
                'email' => 'paciente.admin@clinic.test',
                'phone_number' => '55 1000 0014',
                'country_code' => 'MX',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Paciente del Administrador');
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
            ->assertForbidden();

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/members', [
                'name' => 'Integrante no autorizado',
                'email' => 'integrante.no.autorizado@clinic.test',
                'phone_number' => '55 1000 0015',
                'country_code' => 'MX',
            ])
            ->assertForbidden();
    }

    public function test_clinic_admin_session_uses_a_matching_clinic_admin_membership(): void
    {
        [$receptionistClinic, $receptionistRole] = $this->createClinicRole(RoleKey::Receptionist, 'Recepcionista');
        [$adminClinic, $adminRole] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador Clínico');
        $user = User::factory()->create([
            'phone_number' => '+525510000011',
            'password' => 'SecurePass123',
        ]);

        ClinicMembership::query()->create([
            'clinic_id' => $receptionistClinic->id,
            'user_id' => $user->id,
            'role_id' => $receptionistRole->id,
            'status' => 'active',
        ]);
        ClinicMembership::query()->create([
            'clinic_id' => $adminClinic->id,
            'user_id' => $user->id,
            'role_id' => $adminRole->id,
            'status' => 'active',
        ]);
        $user->roles()->sync([$receptionistRole->id, $adminRole->id]);

        $this->authenticate($user, '55 1000 0011', RoleKey::ClinicAdmin);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/dashboard')
            ->assertOk()
            ->assertJsonPath('data.clinic.id', $adminClinic->id);

        $this->withSpaOrigin()
            ->patchJson('/api/v1/auth/clinic/settings', [
                'name' => 'Admin Clinic Updated',
                'address' => 'Admin Avenue 123',
                'contact_phone' => '55 5555 5511',
            ])
            ->assertOk()
            ->assertJsonPath('data.clinic.id', $adminClinic->id);

        $this->assertDatabaseHas('clinics', [
            'id' => $adminClinic->id,
            'name' => 'Admin Clinic Updated',
        ]);
        $this->assertDatabaseHas('clinics', [
            'id' => $receptionistClinic->id,
            'name' => $receptionistClinic->name,
        ]);
    }

    public function test_clinic_admin_session_skips_unsubscribed_memberships_and_selects_the_first_eligible_clinic(): void
    {
        $unsubscribedClinic = Clinic::factory()->create();
        [$eligibleClinic, $adminRole] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador Clínico');
        $laterEligibleClinic = Clinic::factory()->create();
        ClinicSubscription::factory()->create([
            'clinic_id' => $laterEligibleClinic->id,
            'plan_id' => Plan::query()->value('id'),
        ]);
        $admin = User::factory()->create([
            'phone_number' => '+525510000013',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($unsubscribedClinic, $admin, $adminRole);
        $this->attachMembership($eligibleClinic, $admin, $adminRole);
        $this->attachMembership($laterEligibleClinic, $admin, $adminRole);

        $this->authenticate($admin, '55 1000 0013');

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/dashboard')
            ->assertOk()
            ->assertJsonPath('data.clinic.id', $eligibleClinic->id);
    }

    public function test_clinic_admin_session_is_forbidden_when_its_matching_membership_is_no_longer_active(): void
    {
        [$clinic, $adminRole] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador Clínico');
        $admin = User::factory()->create([
            'phone_number' => '+525510000012',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $admin, $adminRole);
        $this->authenticate($admin, '55 1000 0012');
        $admin->clinicMemberships()->update(['status' => 'suspended']);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'CLINIC_ACCESS_REQUIRED');
    }

    public function test_receptionist_can_read_clinic_appointments_for_a_date_range(): void
    {
        [$clinic, $receptionistRole] = $this->createClinicRole(RoleKey::Receptionist, 'Recepcionista');
        $receptionist = User::factory()->create([
            'phone_number' => '+525510000005',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $receptionist, $receptionistRole);

        $patient = User::factory()->create(['name' => 'Paciente de Agenda']);
        $patientProfile = PatientProfile::factory()->create([
            'user_id' => $patient->id,
            'clinic_id' => $clinic->id,
        ]);
        Appointment::query()->create([
            'clinic_id' => $clinic->id,
            'patient_profile_id' => $patientProfile->id,
            'scheduled_at' => now()->startOfDay()->setTime(9, 30),
            'status' => AppointmentStatus::Confirmed,
            'appointment_type' => 'Consulta',
        ]);

        $this->authenticate($receptionist, '55 1000 0005');

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/appointments?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.0.patient.name', 'Paciente de Agenda')
            ->assertJsonPath('data.0.status', 'confirmed')
            ->assertJsonPath('meta.summary.confirmed', 1);
    }

    public function test_receptionist_can_register_a_patient_inside_their_clinic(): void
    {
        [$clinic, $receptionistRole] = $this->createClinicRole(RoleKey::Receptionist, 'Recepcionista');
        $receptionist = User::factory()->create([
            'phone_number' => '+525510000007',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $receptionist, $receptionistRole);
        $this->authenticate($receptionist, '55 1000 0007');

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/patients', [
                'name' => 'Paciente Registrado',
                'email' => 'paciente.registrado@clinic.test',
                'phone_number' => '55 1000 0008',
                'country_code' => 'MX',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Paciente Registrado')
            ->assertJsonPath('data.status', 'active');

        $patient = User::query()->where('email', 'paciente.registrado@clinic.test')->firstOrFail();
        $this->assertDatabaseHas('patient_profiles', [
            'user_id' => $patient->id,
            'clinic_id' => $clinic->id,
        ]);
    }

    public function test_registering_a_patient_with_an_existing_phone_is_rejected(): void
    {
        [$clinic, $receptionistRole] = $this->createClinicRole(RoleKey::Receptionist, 'Recepcionista');
        $receptionist = User::factory()->create([
            'phone_number' => '+525510000009',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $receptionist, $receptionistRole);
        $existingPatient = User::factory()->create(['phone_number' => '+525510000010']);
        PatientProfile::factory()->create(['user_id' => $existingPatient->id, 'clinic_id' => $clinic->id]);
        $this->authenticate($receptionist, '55 1000 0009');

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/patients', [
                'name' => 'Paciente Duplicado',
                'phone_number' => '55 1000 0010',
                'country_code' => 'MX',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone_number');
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

    public function test_clinic_write_catalog_and_gate_fail_closed_without_a_membership(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(RolePermissionCatalog::definesAbility('clinic.patients.manage'));
        $this->assertTrue(RolePermissionCatalog::allows(RoleKey::ClinicAdmin->value, 'clinic.members.manage'));
        $this->assertTrue(RolePermissionCatalog::allows(RoleKey::Receptionist->value, 'clinic.patients.manage'));
        $this->assertFalse(RolePermissionCatalog::allows(RoleKey::Receptionist->value, 'clinic.members.manage'));
        $this->assertFalse(RolePermissionCatalog::allows('unknown_role', 'clinic.patients.manage'));
        $this->assertFalse(RolePermissionCatalog::definesAbility('clinic.unknown.manage'));
        $this->assertFalse(RolePermissionCatalog::allows(RoleKey::ClinicAdmin->value, 'clinic.unknown.manage'));
        $this->assertFalse(Gate::forUser($user)->allows('clinic.unknown.manage'));
        $this->assertFalse(Gate::forUser($user)->allows('clinic.patients.manage'));
    }

    /** @return array{0: Clinic, 1: Role} */
    private function createClinicRole(RoleKey $roleKey, string $name): array
    {
        $clinic = Clinic::factory()->create();
        ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => Plan::query()->value('id') ?? Plan::factory(),
        ]);
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

    private function authenticate(User $user, string $phone, ?RoleKey $expectedActorContext = null): void
    {
        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => $phone,
            'password' => 'SecurePass123',
        ])->assertAccepted();
        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ])->assertOk()->assertJsonPath('data.actor_context', $expectedActorContext?->value ?? $user->roles()->firstOrFail()->key);
        $sessionCookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue());
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
