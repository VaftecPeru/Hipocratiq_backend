<?php

namespace Tests\Feature;

use App\Enums\DoctorPracticeType;
use App\Enums\LaboratoryOrderStatus;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Enums\TeleconsultationStatus;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\DoctorMessage;
use App\Models\DoctorPrescription;
use App\Models\DoctorProfile;
use App\Models\DoctorSubscription;
use App\Models\PatientProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class DoctorWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        $this->plan = Plan::factory()->create();
        $this->doctor = $this->createIndependentDoctor('+525510000501', $this->plan);
        $this->authenticateAs($this->doctor);
    }

    public function test_independent_doctor_can_use_persistent_workspace_modules(): void
    {
        $patient = $this->ownedPatient('+525510000502');
        $scheduledAt = now()->addDay()->setTime(10, 0);

        $prescription = $this->withSpaOrigin()->postJson('/api/v1/auth/doctor/prescriptions', [
            'patient_profile_id' => $patient->id,
            'medication' => 'Losartán 50 mg',
            'dosage' => '1 tableta',
            'frequency' => 'Cada 24 horas',
            'duration_days' => 30,
        ])->assertCreated()->assertJsonPath('data.status', 'active');

        $this->withSpaOrigin()->postJson('/api/v1/auth/doctor/laboratory', [
            'patient_profile_id' => $patient->id,
            'study_name' => 'Perfil lipídico',
            'priority' => 'urgent',
        ])->assertCreated()->assertJsonPath('data.status', 'requested');

        $teleconsultation = $this->withSpaOrigin()->postJson('/api/v1/auth/doctor/teleconsultations', [
            'patient_profile_id' => $patient->id,
            'scheduled_at' => $scheduledAt->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.connection.joinable', false);

        $this->withSpaOrigin()->postJson('/api/v1/auth/doctor/messages', [
            'patient_profile_id' => $patient->id,
            'body' => 'Tu seguimiento quedó programado.',
        ])->assertCreated()->assertJsonPath('data.patient.id', $patient->id);

        $incomingMessage = DoctorMessage::factory()->create([
            'clinic_id' => null,
            'sender_user_id' => $patient->user_id,
            'recipient_user_id' => $this->doctor->id,
            'patient_profile_id' => $patient->id,
            'body' => 'Gracias, doctor.',
        ]);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/prescriptions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.active', 1)
            ->assertJsonPath('data.0.patient.id', $patient->id);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/laboratory')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.requested', 1);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/teleconsultations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.scheduled', 1)
            ->assertJsonPath('meta.connection.status', 'not_configured');

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/messages')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.unread', 1)
            ->assertJsonPath('data.0.is_received', true);

        $readResponse = $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/messages/{$incomingMessage->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $incomingMessage->id)
            ->assertJsonPath('data.is_received', true);
        $this->assertNotNull($readResponse->json('data.read_at'));

        $outgoingMessage = DoctorMessage::query()
            ->where('sender_user_id', $this->doctor->id)
            ->firstOrFail();
        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/messages/{$outgoingMessage->id}/read")
            ->assertForbidden();

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/messages')
            ->assertJsonPath('meta.unread', 0);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/reports')
            ->assertOk()
            ->assertJsonPath('data.metrics.prescriptions', 1)
            ->assertJsonPath('data.metrics.laboratory_orders', 1)
            ->assertJsonPath('data.metrics.teleconsultations', 1);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/epidemiology')
            ->assertOk()
            ->assertJsonPath('data.analysis_available', false)
            ->assertJsonPath('data.metrics.active_alerts', 0);

        $this->assertDatabaseHas('doctor_prescriptions', [
            'id' => $prescription->json('data.id'),
            'clinic_id' => null,
            'doctor_user_id' => $this->doctor->id,
            'patient_profile_id' => $patient->id,
        ]);
        $this->assertDatabaseHas('teleconsultations', [
            'id' => $teleconsultation->json('data.id'),
            'clinic_id' => null,
            'doctor_user_id' => $this->doctor->id,
        ]);
    }

    public function test_workspace_status_transitions_are_server_validated(): void
    {
        $patient = $this->ownedPatient('+525510000503');
        $order = $this->withSpaOrigin()->postJson('/api/v1/auth/doctor/laboratory', [
            'patient_profile_id' => $patient->id,
            'study_name' => 'Hemograma completo',
        ])->assertCreated();
        $orderId = $order->json('data.id');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/laboratory/{$orderId}/status", ['status' => LaboratoryOrderStatus::Reviewed->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/laboratory/{$orderId}/status", ['status' => LaboratoryOrderStatus::InProgress->value])
            ->assertOk()
            ->assertJsonPath('data.status', LaboratoryOrderStatus::InProgress->value);

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/laboratory/{$orderId}/status", ['status' => LaboratoryOrderStatus::Ready->value])
            ->assertOk()
            ->assertJsonPath('data.status', LaboratoryOrderStatus::Ready->value);

        $session = $this->withSpaOrigin()->postJson('/api/v1/auth/doctor/teleconsultations', [
            'patient_profile_id' => $patient->id,
            'scheduled_at' => now()->addDays(2)->setTime(11, 0)->toIso8601String(),
        ])->assertCreated();
        $sessionId = $session->json('data.id');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/teleconsultations/{$sessionId}/status", ['status' => TeleconsultationStatus::Waiting->value])
            ->assertOk()
            ->assertJsonPath('data.status', TeleconsultationStatus::Waiting->value);
    }

    public function test_clinic_doctor_workspace_is_scoped_to_assigned_clinic(): void
    {
        $clinic = Clinic::factory()->create();
        $clinicDoctor = $this->createClinicDoctor('+525510000506', $clinic);
        $patientUser = User::factory()->create(['name' => 'Paciente de clínica']);
        $patient = $patientUser->patientProfile()->create([
            'clinic_id' => $clinic->id,
            'owner_doctor_user_id' => null,
        ]);

        $otherClinic = Clinic::factory()->create();
        $otherPatient = User::factory()->create()->patientProfile()->create([
            'clinic_id' => $otherClinic->id,
            'owner_doctor_user_id' => null,
        ]);
        $otherPrescription = DoctorPrescription::factory()->create([
            'clinic_id' => $otherClinic->id,
            'doctor_user_id' => $clinicDoctor->id,
            'patient_profile_id' => $otherPatient->id,
        ]);

        $this->authenticateAs($clinicDoctor);

        $this->withSpaOrigin()->postJson('/api/v1/auth/doctor/prescriptions', [
            'patient_profile_id' => $patient->id,
            'medication' => 'Metformina 500 mg',
        ])->assertCreated()->assertJsonPath('data.clinic.id', $clinic->id);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/prescriptions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient.id', $patient->id)
            ->assertJsonPath('data.0.clinic.id', $clinic->id);

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/prescriptions/{$otherPrescription->id}", ['status' => 'canceled'])
            ->assertNotFound();
    }

    public function test_workspace_records_are_isolated_between_independent_doctors(): void
    {
        $otherDoctor = $this->createIndependentDoctor('+525510000504', $this->plan);
        $otherPatient = $this->ownedPatientFor($otherDoctor, '+525510000505');
        $prescription = DoctorPrescription::factory()->create([
            'clinic_id' => null,
            'doctor_user_id' => $otherDoctor->id,
            'patient_profile_id' => $otherPatient->id,
        ]);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/prescriptions')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/prescriptions/{$prescription->id}", ['status' => 'completed'])
            ->assertNotFound();
    }

    private function createIndependentDoctor(string $phone, Plan $plan): User
    {
        $role = Role::query()->firstOrCreate(
            ['key' => RoleKey::Doctor->value],
            ['name' => 'Médico', 'scope' => RoleScope::Organization, 'is_system' => true],
        );
        $doctor = User::factory()->create([
            'name' => 'Dr. Independiente',
            'phone_number' => $phone,
            'password' => 'SecurePass123',
        ]);
        $doctor->roles()->syncWithoutDetaching([$role->id]);
        $profile = DoctorProfile::factory()->create([
            'user_id' => $doctor->id,
            'clinic_id' => null,
            'practice_type' => DoctorPracticeType::Independent,
        ]);
        DoctorSubscription::factory()->create([
            'doctor_profile_id' => $profile->id,
            'plan_id' => $plan->id,
        ]);

        return $doctor;
    }

    private function createClinicDoctor(string $phone, Clinic $clinic): User
    {
        $role = Role::query()->where('key', RoleKey::Doctor->value)->firstOrFail();
        $doctor = User::factory()->create([
            'name' => 'Dr. de Clínica',
            'phone_number' => $phone,
            'password' => 'SecurePass123',
        ]);
        $doctor->roles()->syncWithoutDetaching([$role->id]);
        ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => $this->plan->id,
        ]);
        ClinicMembership::factory()->create([
            'clinic_id' => $clinic->id,
            'user_id' => $doctor->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        DoctorProfile::factory()->create([
            'user_id' => $doctor->id,
            'clinic_id' => $clinic->id,
            'practice_type' => DoctorPracticeType::Clinic,
        ]);

        return $doctor;
    }

    private function ownedPatient(string $phone): PatientProfile
    {
        return $this->ownedPatientFor($this->doctor, $phone);
    }

    private function ownedPatientFor(User $doctor, string $phone): PatientProfile
    {
        $user = User::factory()->create(['phone_number' => $phone]);

        return $user->patientProfile()->create([
            'clinic_id' => null,
            'owner_doctor_user_id' => $doctor->id,
        ]);
    }

    private function authenticateAs(User $user): void
    {
        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => preg_replace('/^\+52/', '', $user->phone_number),
            'password' => 'SecurePass123',
            'requested_role' => 'doctor',
        ])->assertAccepted();
        $session = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ])->assertOk();
        $cookie = $session->getCookie(config('session.cookie'));
        $this->withCookie($cookie->getName(), $cookie->getValue());
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
