<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\DoctorMessage;
use App\Models\PatientProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class ClinicOperationalModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_receptionist_settings_are_persisted_per_user_and_clinic(): void
    {
        [$clinic, $receptionistRole] = $this->createClinicRole(RoleKey::Receptionist, 'Recepcionista');
        $receptionist = User::factory()->create([
            'name' => 'Recepción Principal',
            'phone_number' => '+525510000051',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $receptionist, $receptionistRole);
        $this->authenticate($receptionist, '55 1000 0051', RoleKey::Receptionist);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/receptionist/settings')
            ->assertOk()
            ->assertJsonPath('data.profile.name', 'Recepción Principal')
            ->assertJsonPath('data.settings.persisted', false)
            ->assertJsonPath('data.settings.preferences.new_appointments', true)
            ->assertJsonPath('data.operation.cash_register.status', 'not_configured');

        $this->withSpaOrigin()
            ->patchJson('/api/v1/auth/clinic/receptionist/settings', [
                'preferences' => [
                    'new_appointments' => false,
                    'shift_name' => 'Matutino',
                    'shift_start' => '08:00',
                    'shift_end' => '16:00',
                    'counter_name' => 'Mostrador 1',
                    'paper_size' => 'A4',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.persisted', true)
            ->assertJsonPath('data.settings.preferences.new_appointments', false)
            ->assertJsonPath('data.settings.preferences.shift_name', 'Matutino')
            ->assertJsonPath('data.settings.preferences.paper_size', 'A4');

        $this->assertDatabaseHas('clinic_receptionist_settings', [
            'clinic_id' => $clinic->id,
            'user_id' => $receptionist->id,
        ]);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/receptionist/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.preferences.shift_start', '08:00')
            ->assertJsonPath('data.settings.preferences.counter_name', 'Mostrador 1');
    }

    public function test_clinic_messages_are_tenant_scoped_and_support_read_and_send_transitions(): void
    {
        [$clinic, $receptionistRole] = $this->createClinicRole(RoleKey::Receptionist, 'Recepcionista');
        $doctorRole = $this->createRole(RoleKey::Doctor, 'Médico');
        $receptionist = User::factory()->create(['phone_number' => '+525510000052', 'password' => 'SecurePass123']);
        $doctor = User::factory()->create(['name' => 'Médico de guardia']);
        $patient = User::factory()->create(['name' => 'Paciente de mensajes']);
        $patientProfile = PatientProfile::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $patient->id]);
        $this->attachMembership($clinic, $receptionist, $receptionistRole);
        $this->attachMembership($clinic, $doctor, $doctorRole);

        $incomingMessage = DoctorMessage::query()->create([
            'clinic_id' => $clinic->id,
            'sender_user_id' => $doctor->id,
            'recipient_user_id' => $receptionist->id,
            'body' => 'Revisar agenda de la tarde.',
            'read_at' => null,
        ]);
        DoctorMessage::query()->create([
            'clinic_id' => $clinic->id,
            'sender_user_id' => $patient->id,
            'recipient_user_id' => $receptionist->id,
            'patient_profile_id' => $patientProfile->id,
            'body' => 'Necesito confirmar mi cita.',
            'read_at' => null,
        ]);
        $otherClinic = Clinic::factory()->create();
        $otherPatient = User::factory()->create();
        $otherProfile = PatientProfile::factory()->create(['clinic_id' => $otherClinic->id, 'user_id' => $otherPatient->id]);
        DoctorMessage::query()->create([
            'clinic_id' => $otherClinic->id,
            'sender_user_id' => $otherPatient->id,
            'recipient_user_id' => $receptionist->id,
            'patient_profile_id' => $otherProfile->id,
            'body' => 'No debe aparecer.',
            'read_at' => null,
        ]);

        $this->authenticate($receptionist, '55 1000 0052', RoleKey::Receptionist);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/messages?contact_user_id='.$doctor->id)
            ->assertOk()
            ->assertJsonPath('data.selected_contact_id', $doctor->id)
            ->assertJsonPath('data.messages.0.body', 'Revisar agenda de la tarde.')
            ->assertJsonPath('meta.unread', 2)
            ->assertJsonFragment(['user_id' => $doctor->id])
            ->assertJsonMissing(['body' => 'No debe aparecer.']);

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/clinic/messages/{$incomingMessage->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $incomingMessage->id)
            ->assertJsonPath('data.is_received', true);

        $this->assertNotNull(DoctorMessage::query()->findOrFail($incomingMessage->id)->read_at);

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/messages', [
                'recipient_user_id' => $doctor->id,
                'body' => 'Agenda actualizada.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Agenda actualizada.')
            ->assertJsonPath('data.is_received', false);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/messages?contact_user_id='.$otherPatient->id)
            ->assertNotFound();
    }

    public function test_clinic_epidemiology_returns_internal_events_without_fabricating_analysis(): void
    {
        [$clinic, $adminRole] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador Clínico');
        $admin = User::factory()->create(['phone_number' => '+525510000053', 'password' => 'SecurePass123']);
        $patient = User::factory()->create(['name' => 'Paciente epidemiología']);
        $patientProfile = PatientProfile::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $patient->id]);
        $this->attachMembership($clinic, $admin, $adminRole);
        Appointment::factory()->create([
            'clinic_id' => $clinic->id,
            'patient_profile_id' => $patientProfile->id,
            'scheduled_at' => now()->subDays(2),
            'status' => AppointmentStatus::Completed,
        ]);
        $otherClinic = Clinic::factory()->create();
        $otherProfile = PatientProfile::factory()->create(['clinic_id' => $otherClinic->id]);
        Appointment::factory()->create([
            'clinic_id' => $otherClinic->id,
            'patient_profile_id' => $otherProfile->id,
            'scheduled_at' => now()->subDays(2),
        ]);

        $this->authenticate($admin, '55 1000 0053', RoleKey::ClinicAdmin);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/epidemiology')
            ->assertOk()
            ->assertJsonPath('data.analysis_available', false)
            ->assertJsonPath('data.metrics.appointments', 1)
            ->assertJsonPath('data.metrics.monitored_patients', 1)
            ->assertJsonPath('data.metrics.active_alerts', 0)
            ->assertJsonPath('data.forecasts', [])
            ->assertJsonPath('data.map.status', 'not_configured');
    }

    public function test_future_clinic_subscription_does_not_grant_clinic_access(): void
    {
        [$clinic, $adminRole, $subscription] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador Clínico');
        $admin = User::factory()->create(['phone_number' => '+525510000054', 'password' => 'SecurePass123']);
        $this->attachMembership($clinic, $admin, $adminRole);
        $this->authenticate($admin, '55 1000 0054', RoleKey::ClinicAdmin);
        $subscription->update(['starts_at' => now()->addDay()]);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'CLINIC_ACCESS_REQUIRED');
    }

    /** @return array{0: Clinic, 1: Role, 2: ClinicSubscription} */
    private function createClinicRole(RoleKey $roleKey, string $roleName): array
    {
        $clinic = Clinic::factory()->create();
        $plan = Plan::factory()->create();
        $subscription = ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => $plan->id,
        ]);
        $role = $this->createRole($roleKey, $roleName);

        return [$clinic, $role, $subscription];
    }

    private function createRole(RoleKey $roleKey, string $name): Role
    {
        return Role::query()->create([
            'key' => $roleKey->value,
            'name' => $name,
            'scope' => RoleScope::Organization,
            'is_system' => true,
        ]);
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

    private function authenticate(User $user, string $phone, RoleKey $roleKey): void
    {
        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => $phone,
            'password' => 'SecurePass123',
            'requested_role' => $roleKey->value,
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
