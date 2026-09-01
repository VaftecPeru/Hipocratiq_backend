<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicMembershipStatus;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Enums\UserStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\DoctorProfile;
use App\Models\PatientIntake;
use App\Models\PatientProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use App\Support\Authorization\RolePermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class ClinicPatientDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_clinic_admin_reads_only_their_patient_detail_with_intake_and_appointments(): void
    {
        [$clinic, $admin] = $this->clinicActor(RoleKey::ClinicAdmin, '+525510000301');
        $patient = $this->patient($clinic, [
            'name' => 'Paciente Detalle',
            'date_of_birth' => '1990-01-15',
            'insurance' => 'Seguro privado',
            'is_returning_patient' => true,
            'whatsapp_consent' => true,
        ]);
        $doctor = User::factory()->create(['name' => 'Dra. Prieto']);
        DoctorProfile::factory()->create(['user_id' => $doctor->id, 'clinic_id' => $clinic->id, 'specialty' => 'Cardiología']);
        $specialty = Specialty::factory()->create(['name' => 'Cardiología']);
        PatientIntake::query()->create([
            'clinic_id' => $clinic->id,
            'patient_profile_id' => $patient->id,
            'registered_by_user_id' => $admin->id,
            'requested_doctor_user_id' => $doctor->id,
            'specialty_id' => $specialty->id,
            'reason_category' => 'Consulta',
            'objective' => 'Evaluar síntomas persistentes de manera prioritaria.',
            'triage_priority' => 'urgent',
            'heart_rate' => 82,
        ]);
        Appointment::factory()->create([
            'clinic_id' => $clinic->id,
            'patient_profile_id' => $patient->id,
            'doctor_user_id' => $doctor->id,
            'scheduled_at' => now()->subDay(),
            'status' => AppointmentStatus::Completed,
        ]);
        Appointment::factory()->create([
            'clinic_id' => $clinic->id,
            'patient_profile_id' => $patient->id,
            'doctor_user_id' => $doctor->id,
            'scheduled_at' => now()->addDay(),
            'status' => AppointmentStatus::Confirmed,
        ]);
        $otherPatient = $this->patient(Clinic::factory()->create());

        $this->authenticate($admin, '55 1000 0301');

        $this->withSpaOrigin()
            ->getJson("/api/v1/auth/clinic/patients/{$patient->id}")
            ->assertOk()
            ->assertJsonPath('data.patient.patient_code', 'PAC-'.str_pad((string) $patient->id, 6, '0', STR_PAD_LEFT))
            ->assertJsonPath('data.patient.age', now()->year - 1990)
            ->assertJsonPath('data.profile.document_number', 'DOC-001')
            ->assertJsonPath('data.profile.insurance', 'Seguro privado')
            ->assertJsonPath('data.profile.is_returning_patient', true)
            ->assertJsonPath('data.profile.whatsapp_consent', true)
            ->assertJsonPath('data.intake.requested_doctor.name', 'Dra. Prieto')
            ->assertJsonPath('data.intake.specialty', 'Cardiología')
            ->assertJsonPath('data.intake.triage.priority', 'urgent')
            ->assertJsonPath('data.recent_appointments.0.doctor.name', 'Dra. Prieto')
            ->assertJsonPath('data.next_appointment.doctor.specialty', 'Cardiología')
            ->assertJsonMissingPath('data.intake.objective')
            ->assertJsonMissingPath('data.intake.triage.notes');

        $this->withSpaOrigin()
            ->getJson("/api/v1/auth/clinic/patients/{$otherPatient->id}")
            ->assertNotFound();
    }

    public function test_clinic_admin_updates_administrative_fields_with_validation_and_audit(): void
    {
        [$clinic, $admin] = $this->clinicActor(RoleKey::ClinicAdmin, '+525510000302');
        $patient = $this->patient($clinic, ['name' => 'Paciente Original']);
        User::factory()->create(['email' => 'used@clinic.test']);
        $this->authenticate($admin, '55 1000 0302');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/clinic/patients/{$patient->id}", [
                'name' => 'A',
                'email' => 'used@clinic.test',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/clinic/patients/{$patient->id}", [
                'name' => 'Paciente Actualizado',
                'email' => 'ACTUALIZADO@CLINIC.TEST',
                'phone_number' => '55 1000 0311',
                'country_code' => 'MX',
                'document_type' => 'DNI',
                'document_number' => ' doc-actualizado ',
                'address' => 'Av. Actualizada 10',
                'emergency_contact' => 'Laura 55 1000 0300',
                'insurance' => 'Particular',
                'is_returning_patient' => true,
                'whatsapp_consent' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Paciente Actualizado')
            ->assertJsonPath('data.email', 'actualizado@clinic.test')
            ->assertJsonPath('data.insurance', 'Particular')
            ->assertJsonPath('data.is_returning_patient', true)
            ->assertJsonPath('data.whatsapp_consent', true);

        $this->assertDatabaseHas('patient_profiles', [
            'id' => $patient->id,
            'document_number' => 'DOC-ACTUALIZADO',
            'address' => 'Av. Actualizada 10',
            'insurance' => 'Particular',
            'is_returning_patient' => true,
            'whatsapp_consent' => true,
        ]);
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_user_id' => $admin->id,
            'clinic_id' => $clinic->id,
            'module' => 'clinic_patients',
            'action' => 'patient_updated',
            'resource_id' => (string) $patient->id,
        ]);
    }

    public function test_clinic_admin_can_deactivate_and_reactivate_a_patient_without_deleting_it(): void
    {
        [$clinic, $admin] = $this->clinicActor(RoleKey::ClinicAdmin, '+525510000303');
        $patient = $this->patient($clinic);
        $this->authenticate($admin, '55 1000 0303');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/clinic/patients/{$patient->id}/status", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
        $this->assertDatabaseHas('users', ['id' => $patient->user_id, 'status' => UserStatus::Suspended->value]);
        $this->assertDatabaseHas('patient_profiles', ['id' => $patient->id]);

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/clinic/patients/{$patient->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_user_id' => $admin->id,
            'clinic_id' => $clinic->id,
            'module' => 'clinic_patients',
            'action' => 'patient_status_updated',
            'resource_id' => (string) $patient->id,
        ]);
    }

    public function test_directory_searches_patient_code_and_document_and_reports_summary(): void
    {
        [$clinic, $admin] = $this->clinicActor(RoleKey::ClinicAdmin, '+525510000304');
        $patient = $this->patient($clinic, ['document_number' => 'DOC-SEARCH']);
        $this->patient($clinic, ['document_number' => 'OTHER-DOC']);
        $this->authenticate($admin, '55 1000 0304');

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/patients?search=DOC-SEARCH')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $patient->id)
            ->assertJsonPath('meta.summary.total', 2)
            ->assertJsonPath('meta.summary.active', 2);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/patients?search=PAC-'.str_pad((string) $patient->id, 6, '0', STR_PAD_LEFT))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $patient->id);
    }

    public function test_patient_csv_is_scoped_and_neutralizes_formula_injection(): void
    {
        [$clinic, $admin] = $this->clinicActor(RoleKey::ClinicAdmin, '+525510000305');
        $patient = $this->patient($clinic, ['name' => '=HYPERLINK("https://unsafe.test")']);
        $otherPatient = $this->patient(Clinic::factory()->create(), ['name' => 'Otro Paciente']);
        $this->authenticate($admin, '55 1000 0305');

        $response = $this->withSpaOrigin()
            ->get('/api/v1/auth/clinic/patients/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $header = str_getcsv(explode("\n", $content, 2)[0]);
        $header[0] = str_replace("\xEF\xBB\xBF", '', $header[0]);

        $this->assertSame([
            'Código',
            'Paciente',
            'Documento',
            'Teléfono',
            'Correo',
            'Seguro',
            'Paciente recurrente',
            'WhatsApp',
            'Estado',
            'Registro',
        ], $header);
        $this->assertStringContainsString("'=HYPERLINK", $content);
        $this->assertStringContainsString('PAC-'.str_pad((string) $patient->id, 6, '0', STR_PAD_LEFT), $content);
        $this->assertStringNotContainsString('PAC-'.str_pad((string) $otherPatient->id, 6, '0', STR_PAD_LEFT), $content);
    }

    public function test_receptionist_can_manage_patients_only_because_the_catalog_grants_that_permission(): void
    {
        [$clinic, $receptionist] = $this->clinicActor(RoleKey::Receptionist, '+525510000306');
        $patient = $this->patient($clinic);
        $this->assertTrue(RolePermissionCatalog::allows(RoleKey::Receptionist->value, 'clinic.patients.manage'));
        $this->authenticate($receptionist, '55 1000 0306');

        $this->withSpaOrigin()
            ->getJson("/api/v1/auth/clinic/patients/{$patient->id}")
            ->assertOk();
        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/clinic/patients/{$patient->id}/status", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
    }

    /** @return array{0: Clinic, 1: User} */
    private function clinicActor(RoleKey $roleKey, string $phone): array
    {
        $clinic = Clinic::factory()->create();
        ClinicSubscription::factory()->create(['clinic_id' => $clinic->id, 'plan_id' => Plan::query()->value('id') ?? Plan::factory()]);
        $role = Role::query()->create([
            'key' => $roleKey->value,
            'name' => $roleKey === RoleKey::ClinicAdmin ? 'Clinic administrator' : 'Receptionist',
            'scope' => RoleScope::Organization,
            'is_system' => true,
        ]);
        $actor = User::factory()->create(['phone_number' => $phone, 'password' => 'SecurePass123']);
        $actor->roles()->attach($role);
        ClinicMembership::query()->create([
            'clinic_id' => $clinic->id,
            'user_id' => $actor->id,
            'role_id' => $role->id,
            'status' => ClinicMembershipStatus::Active,
        ]);

        return [$clinic, $actor];
    }

    /** @param array<string, mixed> $overrides */
    private function patient(Clinic $clinic, array $overrides = []): PatientProfile
    {
        $user = User::factory()->create([
            'name' => $overrides['name'] ?? 'Paciente de Directorio',
            'email' => $overrides['email'] ?? fake()->unique()->safeEmail(),
            'phone_number' => $overrides['phone_number'] ?? fake()->unique()->numerify('+5255100#######'),
            'status' => $overrides['status'] ?? UserStatus::Active,
        ]);

        return PatientProfile::factory()->create([
            'user_id' => $user->id,
            'clinic_id' => $clinic->id,
            'document_type' => $overrides['document_type'] ?? 'DNI',
            'document_number' => $overrides['document_number'] ?? 'DOC-001',
            'date_of_birth' => $overrides['date_of_birth'] ?? null,
            'sex' => $overrides['sex'] ?? 'Femenino',
            'address' => $overrides['address'] ?? 'Av. Salud 1',
            'emergency_contact' => $overrides['emergency_contact'] ?? 'Contacto 55 1000 0300',
            'insurance' => $overrides['insurance'] ?? null,
            'is_returning_patient' => $overrides['is_returning_patient'] ?? false,
            'whatsapp_consent' => $overrides['whatsapp_consent'] ?? false,
        ]);
    }

    private function authenticate(User $user, string $phone): void
    {
        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => $phone,
            'password' => 'SecurePass123',
            'requested_role' => $user->roles()->firstOrFail()->key,
        ])->assertAccepted();
        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ])->assertOk();
        $cookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($cookie->getName(), $cookie->getValue());
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
