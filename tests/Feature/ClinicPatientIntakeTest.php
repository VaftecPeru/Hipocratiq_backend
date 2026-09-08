<?php

namespace Tests\Feature;

use App\Enums\ClinicMembershipStatus;
use App\Enums\DoctorPracticeType;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\DoctorProfile;
use App\Models\PatientProfile;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class ClinicPatientIntakeTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;

    private Plan $plan;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        $this->plan = Plan::factory()->create();
        $this->clinic = Clinic::factory()->create();
        ClinicSubscription::factory()->create(['clinic_id' => $this->clinic->id, 'plan_id' => $this->plan->id]);
        $receptionistRole = Role::query()->create(['key' => RoleKey::Receptionist->value, 'name' => 'Recepcionista', 'scope' => RoleScope::Organization, 'is_system' => true]);
        Role::query()->create(['key' => RoleKey::Doctor->value, 'name' => 'Médico', 'scope' => RoleScope::Organization, 'is_system' => true]);
        $this->receptionist = User::factory()->create(['phone_number' => '+525510000201', 'password' => 'SecurePass123']);
        $this->receptionist->roles()->attach($receptionistRole);
        ClinicMembership::query()->create(['clinic_id' => $this->clinic->id, 'user_id' => $this->receptionist->id, 'role_id' => $receptionistRole->id, 'status' => ClinicMembershipStatus::Active]);
        $this->seed(SpecialtySeeder::class);
        $this->authenticate();
    }

    public function test_receptionist_persists_demographics_and_explicit_intake_objective_in_one_transaction(): void
    {
        $doctor = $this->clinicDoctor($this->clinic, '+525510000202');
        $specialty = Specialty::query()->where('code', 'medicina_interna')->sole();
        $subspecialty = Specialty::query()->where('code', 'cardiologia')->sole();

        $this->withSpaOrigin()->postJson('/api/v1/auth/clinic/patients', $this->payload([
            'requested_doctor_user_id' => $doctor->id,
            'specialty_id' => $specialty->id,
            'subspecialty_id' => $subspecialty->id,
            'insurance' => 'EsSalud',
            'is_returning_patient' => true,
            'whatsapp_consent' => true,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Paciente con Ingreso')
            ->assertJsonPath('data.insurance', 'EsSalud')
            ->assertJsonPath('data.is_returning_patient', true)
            ->assertJsonPath('data.whatsapp_consent', true);

        $this->assertDatabaseHas('patient_profiles', [
            'clinic_id' => $this->clinic->id,
            'document_type' => 'DNI',
            'document_number' => '12345678',
            'sex' => 'Femenino',
            'address' => 'Av. Salud 123',
            'insurance' => 'EsSalud',
            'is_returning_patient' => true,
            'whatsapp_consent' => true,
        ]);
        $this->assertDatabaseHas('patient_intakes', [
            'clinic_id' => $this->clinic->id,
            'registered_by_user_id' => $this->receptionist->id,
            'requested_doctor_user_id' => $doctor->id,
            'specialty_id' => $specialty->id,
            'subspecialty_id' => $subspecialty->id,
            'objective' => 'Evaluar dolor torácico de inicio reciente.',
        ]);
    }

    public function test_rejects_requested_doctor_from_another_clinic_and_rolls_back_patient_creation(): void
    {
        $otherClinic = Clinic::factory()->create();
        ClinicSubscription::factory()->create(['clinic_id' => $otherClinic->id, 'plan_id' => $this->plan->id]);
        $otherDoctor = $this->clinicDoctor($otherClinic, '+525510000203');

        $this->withSpaOrigin()->postJson('/api/v1/auth/clinic/patients', $this->payload([
            'email' => 'cross-clinic@patient.test',
            'phone_number' => '55 1000 0204',
            'requested_doctor_user_id' => $otherDoctor->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('requested_doctor_user_id');

        $this->assertDatabaseMissing('users', ['email' => 'cross-clinic@patient.test']);
        $this->assertDatabaseCount('patient_intakes', 0);
    }

    public function test_receptionist_persists_triage_values_on_intake_and_returns_them(): void
    {
        $this->withSpaOrigin()->postJson('/api/v1/auth/clinic/patients', $this->payload([
            'temperature_c' => 37.2,
            'systolic_bp' => 118,
            'diastolic_bp' => 76,
            'heart_rate' => 82,
            'respiratory_rate' => 16,
            'oxygen_saturation' => 97,
            'triage_priority' => 'urgent',
            'triage_notes' => 'Fiebre leve y taquicardia durante la valoración.',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Paciente con Ingreso')
            ->assertJsonPath('data.triage.temperature_c', 37.2)
            ->assertJsonPath('data.triage.systolic_bp', 118)
            ->assertJsonPath('data.triage.diastolic_bp', 76)
            ->assertJsonPath('data.triage.heart_rate', 82)
            ->assertJsonPath('data.triage.respiratory_rate', 16)
            ->assertJsonPath('data.triage.oxygen_saturation', 97)
            ->assertJsonPath('data.triage.priority', 'urgent')
            ->assertJsonPath('data.triage.notes', 'Fiebre leve y taquicardia durante la valoración.');

        $profileId = PatientProfile::query()
            ->where('clinic_id', $this->clinic->id)
            ->whereHas('user', fn ($query) => $query->where('name', 'Paciente con Ingreso'))
            ->value('id');

        $this->assertDatabaseHas('patient_intakes', [
            'clinic_id' => $this->clinic->id,
            'patient_profile_id' => $profileId,
            'registered_by_user_id' => $this->receptionist->id,
            'temperature_c' => 37.2,
            'systolic_bp' => 118,
            'diastolic_bp' => 76,
            'heart_rate' => 82,
            'respiratory_rate' => 16,
            'oxygen_saturation' => 97,
            'triage_priority' => 'urgent',
            'triage_notes' => 'Fiebre leve y taquicardia durante la valoración.',
        ]);
    }

    public function test_emergency_intake_uses_effective_policy_and_records_a_critical_audit_event(): void
    {
        $settings = PlatformSetting::query()->findOrFail(1);
        $settings->forceFill([
            'emergency_policy' => [
                'enabled' => true,
                'label' => 'Código rojo global',
                'default_channel' => 'priority_push',
                'escalation_minutes' => 1,
            ],
            'emergency_responsibles' => [
                ['role' => 'Jefe de guardia', 'channel' => 'Push prioritario'],
            ],
            'emergency_published_at' => now(),
            'emergency_publication' => [
                'scope' => 'all',
                'force' => true,
                'policy' => [
                    'enabled' => true,
                    'label' => 'Código rojo global',
                    'default_channel' => 'priority_push',
                    'escalation_minutes' => 1,
                ],
                'responsibles' => [
                    ['role' => 'Jefe de guardia', 'channel' => 'Push prioritario'],
                ],
            ],
        ])->save();

        $this->withSpaOrigin()->postJson('/api/v1/auth/clinic/patients', $this->payload([
            'triage_priority' => 'emergency',
            'triage_notes' => 'Dolor torácico requiere atención inmediata.',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.triage.priority', 'emergency');

        $this->assertDatabaseHas('admin_audit_events', [
            'clinic_id' => $this->clinic->id,
            'module' => 'emergency',
            'action' => 'emergency_intake_created',
            'is_critical' => true,
        ]);
    }

    public function test_rejects_emergency_intake_when_effective_policy_is_disabled(): void
    {
        $settings = PlatformSetting::query()->findOrFail(1);
        $settings->forceFill([
            'emergency_policy' => ['enabled' => false],
            'emergency_published_at' => now(),
            'emergency_publication' => [
                'scope' => 'all',
                'force' => true,
                'policy' => ['enabled' => false],
                'responsibles' => [],
            ],
        ])->save();

        $this->withSpaOrigin()->postJson('/api/v1/auth/clinic/patients', $this->payload([
            'triage_priority' => 'emergency',
            'triage_notes' => 'Ingreso urgente bloqueado por política deshabilitada.',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('triage_priority');

        $this->assertDatabaseCount('patient_intakes', 0);
        $this->assertDatabaseMissing('users', ['email' => 'patient.intake@clinic.test']);
    }

    public function test_rejects_triage_values_outside_reasonable_bounds_and_rolls_back_patient(): void
    {
        $this->withSpaOrigin()->postJson('/api/v1/auth/clinic/patients', $this->payload([
            'systolic_bp' => 30,
        ]))->assertUnprocessable()->assertJsonValidationErrors('systolic_bp');

        $this->assertDatabaseMissing('users', ['email' => 'patient.intake@clinic.test']);
        $this->assertDatabaseCount('patient_intakes', 0);
        $this->assertDatabaseCount('patient_profiles', $this->clinic->patientProfiles()->count());
    }

    public function test_rejects_diastolic_bp_not_less_than_systolic_bp(): void
    {
        $this->withSpaOrigin()->postJson('/api/v1/auth/clinic/patients', $this->payload([
            'systolic_bp' => 110,
            'diastolic_bp' => 120,
        ]))->assertUnprocessable()->assertJsonValidationErrors('diastolic_bp');

        $this->assertDatabaseCount('patient_intakes', 0);
        $this->assertDatabaseMissing('users', ['email' => 'patient.intake@clinic.test']);
    }

    public function test_rejects_invalid_triage_priority_value(): void
    {
        $this->withSpaOrigin()->postJson('/api/v1/auth/clinic/patients', $this->payload([
            'triage_priority' => 'critical',
        ]))->assertUnprocessable()->assertJsonValidationErrors('triage_priority');

        $this->assertDatabaseCount('patient_intakes', 0);
    }

    public function test_omitting_triage_preserves_legacy_minimal_registration(): void
    {
        $this->withSpaOrigin()->postJson('/api/v1/auth/clinic/patients', [
            'name' => 'Paciente Mínimo',
            'email' => 'minimal@clinic.test',
            'phone_number' => '55 1000 0205',
            'country_code' => 'MX',
        ])->assertCreated()->assertJsonPath('data.name', 'Paciente Mínimo');

        $this->assertDatabaseHas('patient_profiles', [
            'clinic_id' => $this->clinic->id,
            'document_number' => null,
        ]);
        $this->assertDatabaseCount('patient_intakes', 0);
    }

    private function clinicDoctor(Clinic $clinic, string $phone): User
    {
        $doctorRole = Role::query()->where('key', RoleKey::Doctor->value)->sole();
        $doctor = User::factory()->create(['phone_number' => $phone]);
        $doctor->roles()->attach($doctorRole);
        DoctorProfile::factory()->create(['user_id' => $doctor->id, 'clinic_id' => $clinic->id, 'practice_type' => DoctorPracticeType::Clinic]);
        ClinicMembership::query()->create(['clinic_id' => $clinic->id, 'user_id' => $doctor->id, 'role_id' => $doctorRole->id, 'status' => ClinicMembershipStatus::Active]);

        return $doctor;
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Paciente con Ingreso',
            'email' => 'patient.intake@clinic.test',
            'phone_number' => '55 1000 0200',
            'country_code' => 'MX',
            'document_type' => 'DNI',
            'document_number' => '12345678',
            'date_of_birth' => '1990-01-15',
            'sex' => 'Femenino',
            'address' => 'Av. Salud 123',
            'emergency_contact' => 'Ana Pérez +525510002000',
            'requested_doctor_user_id' => null,
            'specialty_id' => null,
            'subspecialty_id' => null,
            'reason_category' => 'Consulta',
            'objective' => 'Evaluar dolor torácico de inicio reciente.',
            'observations' => 'Paciente refiere molestias desde hace dos días.',
        ];
    }

    private function authenticate(): void
    {
        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => '55 1000 0201',
            'password' => 'SecurePass123',
            'requested_role' => 'receptionist',
        ])->assertAccepted();
        $session = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ])->assertOk()->assertJsonPath('data.actor_context', 'receptionist');
        $cookie = $session->getCookie(config('session.cookie'));
        $this->withCookie($cookie->getName(), $cookie->getValue());
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
