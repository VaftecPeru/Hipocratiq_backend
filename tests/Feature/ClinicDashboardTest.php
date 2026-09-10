<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicScheduleBlock;
use App\Models\ClinicSubscription;
use App\Models\DoctorProfile;
use App\Models\PatientProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use App\Support\Authorization\RolePermissionCatalog;
use App\Support\Clinical\DoctorScheduling;
use Carbon\CarbonImmutable;
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
                'preferences' => [
                    'maintenance' => true,
                    'audit' => false,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.clinic.name', 'Clínica Actualizada')
            ->assertJsonPath('data.clinic.preferences.maintenance', true)
            ->assertJsonPath('data.clinic.preferences.audit', false)
            ->assertJsonPath('data.clinic.preferences.signatures', true)
            ->assertJsonPath('data.clinic.preferences.backups', true);

        $this->assertDatabaseHas('clinics', [
            'id' => $clinic->id,
            'name' => 'Clínica Actualizada',
            'address' => 'Av. Nueva 123',
        ]);
        $this->assertSame([
            'maintenance' => true,
            'audit' => false,
            'signatures' => true,
            'backups' => true,
        ], $clinic->fresh()->preferences);

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

    public function test_clinic_admin_can_update_local_emergency_policy_and_receive_effective_state(): void
    {
        [$clinic, $adminRole] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador Clínico');
        $admin = User::factory()->create([
            'phone_number' => '+525510000033',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $admin, $adminRole);
        $this->authenticate($admin, '55 1000 0033');

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/settings')
            ->assertOk()
            ->assertJsonPath('data.clinic.emergency.is_overridden', false)
            ->assertJsonPath('data.clinic.emergency.effective.enabled', true);

        $this->withSpaOrigin()
            ->patchJson('/api/v1/auth/clinic/settings', [
                'preferences' => [
                    'emergency' => [
                        'enabled' => false,
                        'visibility' => 'doctors_admins',
                        'label' => 'Alerta clínica',
                        'color' => '#F97316',
                        'inheritance' => 'responsibles_only',
                        'default_channel' => 'internal_alert',
                        'escalation_minutes' => 5,
                        'responsibles' => [['role' => 'Jefe local', 'channel' => 'Alerta interna']],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.clinic.preferences.emergency.label', 'Alerta clínica')
            ->assertJsonPath('data.clinic.emergency.local.enabled', false)
            ->assertJsonPath('data.clinic.emergency.effective.default_channel', 'internal_alert')
            ->assertJsonPath('data.clinic.emergency.global_override', null);

        self::assertSame('Alerta clínica', $clinic->fresh()->preferences['emergency']['label']);
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

    public function test_clinic_appointments_enforce_doctor_schedule_status_transitions_and_active_doctors(): void
    {
        [$clinic, $receptionistRole] = $this->createClinicRole(RoleKey::Receptionist, 'Recepcionista');
        $doctorRole = Role::query()->create([
            'key' => RoleKey::Doctor->value,
            'name' => 'Médico',
            'scope' => RoleScope::Organization,
            'is_system' => true,
        ]);
        $receptionist = User::factory()->create([
            'phone_number' => '+525510000006',
            'password' => 'SecurePass123',
        ]);
        $activeDoctor = User::factory()->create(['name' => 'Médico Activo']);
        $inactiveDoctor = User::factory()->create(['name' => 'Médico Inactivo', 'status' => 'suspended']);
        $this->attachMembership($clinic, $receptionist, $receptionistRole);
        $this->attachMembership($clinic, $activeDoctor, $doctorRole);
        $this->attachMembership($clinic, $inactiveDoctor, $doctorRole);
        DoctorProfile::factory()->create([
            'user_id' => $activeDoctor->id,
            'clinic_id' => $clinic->id,
            'practice_type' => 'clinic',
        ]);
        DoctorProfile::factory()->create([
            'user_id' => $inactiveDoctor->id,
            'clinic_id' => $clinic->id,
            'practice_type' => 'clinic',
        ]);
        $patient = User::factory()->create(['name' => 'Paciente de agenda']);
        $patientProfile = PatientProfile::factory()->create([
            'user_id' => $patient->id,
            'clinic_id' => $clinic->id,
        ]);
        $scheduledAt = now()->addWeek()->startOfWeek()->setTime(10, 0);

        $this->authenticate($receptionist, '55 1000 0006', RoleKey::Receptionist);

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/appointments', [
                'patient_profile_id' => $patientProfile->id,
                'doctor_user_id' => $activeDoctor->id,
                'scheduled_at' => $scheduledAt->toIso8601String(),
                'appointment_type' => 'Consulta general',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $appointment = Appointment::query()->latest('id')->firstOrFail();

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/appointments', [
                'patient_profile_id' => $patientProfile->id,
                'doctor_user_id' => $activeDoctor->id,
                'scheduled_at' => $scheduledAt->toIso8601String(),
                'appointment_type' => 'Consulta duplicada',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');

        $secondAppointment = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/appointments', [
                'patient_profile_id' => $patientProfile->id,
                'doctor_user_id' => $activeDoctor->id,
                'scheduled_at' => $scheduledAt->copy()->addHours(2)->toIso8601String(),
                'appointment_type' => 'Consulta reprogramable',
            ])
            ->assertCreated()
            ->json('data');
        $secondAppointmentOriginalTime = Appointment::query()->findOrFail($secondAppointment['id'])->scheduled_at;

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/clinic/appointments/{$secondAppointment['id']}", [
                'scheduled_at' => $scheduledAt->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');

        self::assertTrue(Appointment::query()->findOrFail($secondAppointment['id'])->scheduled_at->equalTo($secondAppointmentOriginalTime));

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/clinic/appointments/{$appointment->id}", [
                'doctor_user_id' => $inactiveDoctor->id,
                'scheduled_at' => $scheduledAt->copy()->addHours(4)->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doctor_user_id');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'doctor_user_id' => $activeDoctor->id,
        ]);

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/appointments', [
                'patient_profile_id' => $patientProfile->id,
                'doctor_user_id' => $inactiveDoctor->id,
                'scheduled_at' => $scheduledAt->copy()->addHours(2)->toIso8601String(),
                'appointment_type' => 'Médico inactivo',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doctor_user_id');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/clinic/appointments/{$appointment->id}/status", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/clinic/appointments/{$appointment->id}/status", ['status' => 'completed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/appointments?from='.$scheduledAt->toDateString().'&to='.$scheduledAt->toDateString())
            ->assertOk()
            ->assertJsonCount(2, 'data');
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
            ->assertJsonPath('data.country_code', 'MX')
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

    public function test_clinic_admin_can_create_a_doctor_with_a_catalog_specialty(): void
    {
        [$clinic, $adminRole] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador Clínico');
        $doctorRole = Role::query()->create([
            'key' => RoleKey::Doctor->value,
            'name' => 'Médico',
            'scope' => RoleScope::Organization,
            'is_system' => true,
        ]);
        $specialty = Specialty::query()->create([
            'code' => 'medicina_interna',
            'name' => 'Medicina Interna',
            'parent_id' => null,
            'is_active' => true,
        ]);
        $subspecialty = Specialty::query()->create([
            'code' => 'cardiologia',
            'name' => 'Cardiología',
            'parent_id' => $specialty->id,
            'is_active' => true,
        ]);
        $admin = User::factory()->create([
            'phone_number' => '+525510000021',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $admin, $adminRole);
        $this->authenticate($admin, '55 1000 0021');

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/members/doctors', [
                'name' => 'Dra. Elena Ruiz',
                'email' => 'elena.ruiz@clinic.test',
                'phone_number' => '55 1000 0022',
                'country_code' => 'MX',
                'medical_license' => ' med-123456 ',
                'specialty_id' => $specialty->id,
                'subspecialty_id' => $subspecialty->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Dra. Elena Ruiz')
            ->assertJsonPath('data.role', 'doctor')
            ->assertJsonPath('data.specialty', 'Medicina Interna')
            ->assertJsonPath('data.medical_license', 'MED-123456')
            ->assertJsonPath('meta.requires_password_reset', true);

        $doctor = User::query()->where('email', 'elena.ruiz@clinic.test')->firstOrFail();
        $this->assertDatabaseHas('doctor_profiles', [
            'user_id' => $doctor->id,
            'clinic_id' => $clinic->id,
            'medical_license' => 'MED-123456',
            'specialty' => 'Medicina Interna',
            'specialty_id' => $specialty->id,
            'subspecialty_id' => $subspecialty->id,
            'practice_type' => 'clinic',
        ]);
        $this->assertDatabaseHas('clinic_memberships', [
            'user_id' => $doctor->id,
            'clinic_id' => $clinic->id,
            'role_id' => $doctorRole->id,
            'status' => 'active',
        ]);
    }

    public function test_clinic_admin_cannot_create_a_doctor_without_license_or_specialty(): void
    {
        [$clinic, $adminRole] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador Clínico');
        $admin = User::factory()->create([
            'phone_number' => '+525510000023',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $admin, $adminRole);
        $this->authenticate($admin, '55 1000 0023');

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/members/doctors', [
                'name' => 'Médico sin datos profesionales',
                'email' => 'medico.incompleto@clinic.test',
                'phone_number' => '55 1000 0024',
                'country_code' => 'MX',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['medical_license', 'specialty_id']);

        $this->assertDatabaseMissing('users', ['email' => 'medico.incompleto@clinic.test']);
        $this->assertDatabaseCount('doctor_profiles', 0);
    }

    public function test_clinic_admin_cannot_create_a_doctor_with_a_duplicate_license(): void
    {
        [$clinic, $adminRole] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador Clínico');
        $specialty = Specialty::query()->create([
            'code' => 'pediatria',
            'name' => 'Pediatría',
            'parent_id' => null,
            'is_active' => true,
        ]);
        $existingDoctor = User::factory()->create(['phone_number' => '+525510000025']);
        DoctorProfile::factory()->create([
            'user_id' => $existingDoctor->id,
            'medical_license' => 'MED-DUPLICATE',
        ]);
        $admin = User::factory()->create([
            'phone_number' => '+525510000026',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $admin, $adminRole);
        $this->authenticate($admin, '55 1000 0026');

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/members/doctors', [
                'name' => 'Otro Médico',
                'email' => 'otro.medico@clinic.test',
                'phone_number' => '55 1000 0027',
                'country_code' => 'MX',
                'medical_license' => ' med-duplicate ',
                'specialty_id' => $specialty->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('medical_license');

        $this->assertDatabaseMissing('users', ['email' => 'otro.medico@clinic.test']);
        $this->assertDatabaseCount('doctor_profiles', 1);
    }

    public function test_receptionist_cannot_create_a_doctor(): void
    {
        [$clinic, $receptionistRole] = $this->createClinicRole(RoleKey::Receptionist, 'Recepcionista');
        $receptionist = User::factory()->create([
            'phone_number' => '+525510000028',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $receptionist, $receptionistRole);
        $this->authenticate($receptionist, '55 1000 0028');

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/members/doctors', [
                'name' => 'Intento no autorizado',
                'phone_number' => '55 1000 0029',
                'country_code' => 'MX',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['name' => 'Intento no autorizado']);
        $this->assertDatabaseCount('doctor_profiles', 0);
    }

    public function test_clinic_admin_can_remove_another_active_member_without_deleting_the_user(): void
    {
        [$clinic, $adminRole] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador Clínico');
        $receptionistRole = Role::query()->create([
            'key' => RoleKey::Receptionist->value,
            'name' => 'Recepcionista',
            'scope' => RoleScope::Organization,
            'is_system' => true,
        ]);
        $admin = User::factory()->create([
            'phone_number' => '+525510000030',
            'password' => 'SecurePass123',
        ]);
        $member = User::factory()->create([
            'name' => 'Recepcionista a retirar',
            'phone_number' => '+525510000031',
        ]);
        $this->attachMembership($clinic, $admin, $adminRole);
        $this->attachMembership($clinic, $member, $receptionistRole);
        $this->authenticate($admin, '55 1000 0030');

        $this->withSpaOrigin()
            ->deleteJson("/api/v1/auth/clinic/members/{$member->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('clinic_memberships', [
            'clinic_id' => $clinic->id,
            'user_id' => $member->id,
            'status' => 'suspended',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_user_id' => $admin->id,
            'clinic_id' => $clinic->id,
            'module' => 'clinic_team',
            'action' => 'member_removed',
            'resource_id' => (string) $member->id,
        ]);
    }

    public function test_clinic_admin_cannot_remove_itself_from_the_clinic(): void
    {
        [$clinic, $adminRole] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador Clínico');
        $admin = User::factory()->create([
            'phone_number' => '+525510000032',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $admin, $adminRole);
        $this->authenticate($admin, '55 1000 0032');

        $this->withSpaOrigin()
            ->deleteJson("/api/v1/auth/clinic/members/{$admin->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No puedes eliminarte a ti mismo del equipo.');

        $this->assertDatabaseHas('clinic_memberships', [
            'clinic_id' => $clinic->id,
            'user_id' => $admin->id,
            'status' => 'active',
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

    public function test_schedule_blocks_reject_overlapping_appointments_and_remain_scoped_to_clinic(): void
    {
        [$clinic, $role] = $this->createClinicRole(RoleKey::Receptionist, 'Recepcionista');
        $receptionist = User::factory()->create(['phone_number' => '+525510000041', 'password' => 'SecurePass123']);
        $this->attachMembership($clinic, $receptionist, $role);
        $doctorRole = Role::query()->create(['key' => RoleKey::Doctor->value, 'name' => 'Médico', 'scope' => RoleScope::Organization, 'is_system' => true]);
        $doctor = User::factory()->create();
        $this->attachMembership($clinic, $doctor, $doctorRole);
        $profile = DoctorProfile::factory()->create(['user_id' => $doctor->id, 'clinic_id' => $clinic->id, 'practice_type' => 'clinic']);
        $patient = PatientProfile::factory()->create(['clinic_id' => $clinic->id]);
        $start = now()->addWeek()->startOfWeek()->setTime(10, 0);
        $this->authenticate($receptionist, '55 1000 0041', RoleKey::Receptionist);

        $block = $this->withSpaOrigin()->postJson('/api/v1/auth/clinic/schedule-blocks', [
            'doctor_user_id' => $doctor->id,
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->copy()->addHour()->toIso8601String(),
            'reason' => 'Reunión',
        ])->assertCreated()->json('data');

        $payload = ['patient_profile_id' => $patient->id, 'doctor_user_id' => $doctor->id, 'scheduled_at' => $start->toIso8601String(), 'appointment_type' => 'Consulta'];
        $this->withSpaOrigin()->postJson('/api/v1/auth/clinic/appointments', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at']);
        $availability = app(DoctorScheduling::class)->availability($profile, $clinic, CarbonImmutable::parse($start));
        $slot = collect($availability['slots'])->firstWhere('time', '10:00');
        $this->assertFalse($slot['available']);
        $this->assertSame('blocked', $slot['reason']);

        $foreignClinic = Clinic::factory()->create();
        $foreignBlock = ClinicScheduleBlock::query()->create([
            'clinic_id' => $foreignClinic->id, 'created_by_user_id' => $receptionist->id,
            'starts_at' => $start, 'ends_at' => $start->copy()->addHour(),
        ]);
        $this->withSpaOrigin()->getJson('/api/v1/auth/clinic/schedule-blocks?from='.$start->toDateString().'&to='.$start->toDateString())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $block['id']);
        $this->withSpaOrigin()->deleteJson('/api/v1/auth/clinic/schedule-blocks/'.$foreignBlock->id)->assertNotFound();
        $this->assertDatabaseHas('clinic_schedule_blocks', ['id' => $foreignBlock->id]);

        $this->withSpaOrigin()->deleteJson('/api/v1/auth/clinic/schedule-blocks/'.$block['id'])->assertOk();
        $appointment = $this->withSpaOrigin()->postJson('/api/v1/auth/clinic/appointments', $payload)->assertCreated()->json('data');
        $laterBlock = ClinicScheduleBlock::query()->create([
            'clinic_id' => $clinic->id, 'created_by_user_id' => $receptionist->id,
            'starts_at' => $start->copy()->addHours(2), 'ends_at' => $start->copy()->addHours(3),
        ]);
        $this->withSpaOrigin()->patchJson('/api/v1/auth/clinic/appointments/'.$appointment['id'], [
            'scheduled_at' => $start->copy()->addHours(2)->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at']);
        $this->withSpaOrigin()->deleteJson('/api/v1/auth/clinic/appointments/'.$appointment['id'])->assertOk();
        $this->assertDatabaseMissing('appointments', ['id' => $appointment['id']]);
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
            'requested_role' => $expectedActorContext?->value ?? $user->roles()->firstOrFail()->key,
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
