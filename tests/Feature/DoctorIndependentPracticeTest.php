<?php

namespace Tests\Feature;

use App\Enums\DoctorPracticeType;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Enums\UserStatus;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\DoctorProfile;
use App\Models\DoctorSubscription;
use App\Models\PatientProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Database\Seeders\ClinicalHistorySectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class DoctorIndependentPracticeTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        $this->plan = Plan::factory()->create();
        $this->doctor = $this->createIndependentDoctor('+525510000401');
        $this->authenticateAs($this->doctor);
    }

    public function test_independent_doctor_can_create_list_and_update_owned_patient(): void
    {
        $response = $this->withSpaOrigin()->postJson('/api/v1/auth/doctor/patients', [
            'name' => 'Paciente Independiente',
            'email' => 'patient.independent@example.test',
            'phone_number' => '55 1000 0042',
            'country_code' => 'MX',
            'document_type' => 'INE',
            'document_number' => 'INE-0042',
            'date_of_birth' => '1990-04-02',
            'sex' => 'Femenino',
            'whatsapp_consent' => true,
        ])->assertCreated();

        $patientId = $response->json('data.id');

        $this->assertDatabaseHas('patient_profiles', [
            'id' => $patientId,
            'clinic_id' => null,
            'owner_doctor_user_id' => $this->doctor->id,
            'document_number' => 'INE-0042',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => PatientProfile::query()->findOrFail($patientId)->user_id,
            'status' => UserStatus::Active->value,
            'password' => null,
        ]);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/patients')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $patientId)
            ->assertJsonPath('data.0.patient_code', 'PAC-'.str_pad((string) $patientId, 6, '0', STR_PAD_LEFT));

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/patients/{$patientId}", [
                'name' => 'Paciente Actualizado',
                'phone_number' => '55 1000 0043',
                'country_code' => 'MX',
                'insurance' => 'Seguro privado',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Paciente Actualizado')
            ->assertJsonPath('data.insurance', 'Seguro privado');

        $this->assertDatabaseHas('users', [
            'id' => PatientProfile::query()->findOrFail($patientId)->user_id,
            'name' => 'Paciente Actualizado',
            'phone_number' => '+525510000043',
        ]);
    }

    public function test_independent_doctor_cannot_read_or_use_another_doctors_patient(): void
    {
        $otherDoctor = $this->createIndependentDoctor('+525510000404');
        $otherPatientUser = User::factory()->create([
            'name' => 'Paciente de Otro Médico',
            'phone_number' => '+525510000405',
        ]);
        $otherPatient = $otherPatientUser->patientProfile()->create([
            'clinic_id' => null,
            'owner_doctor_user_id' => $otherDoctor->id,
        ]);

        $this->withSpaOrigin()
            ->getJson("/api/v1/auth/doctor/patients/{$otherPatient->id}")
            ->assertNotFound();

        $this->withSpaOrigin()
            ->getJson("/api/v1/auth/doctor/patients/{$otherPatient->id}/clinical-history")
            ->assertNotFound();

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/doctor/appointments', [
                'patient_profile_id' => $otherPatient->id,
                'scheduled_at' => now()->addDay()->setTime(10, 0)->toIso8601String(),
                'appointment_type' => 'consultation',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_independent_doctor_can_create_update_list_and_cancel_appointment(): void
    {
        $patient = $this->ownedPatient('+525510000406');
        $scheduledAt = now()->nextWeekday()->setTime(10, 0);

        $response = $this->withSpaOrigin()->postJson('/api/v1/auth/doctor/appointments', [
            'patient_profile_id' => $patient->id,
            'scheduled_at' => $scheduledAt->toIso8601String(),
            'appointment_type' => 'control',
            'notes' => 'Primera consulta en consultorio independiente.',
        ])->assertCreated();

        $appointmentId = $response->json('data.id');
        $this->assertDatabaseHas('appointments', [
            'id' => $appointmentId,
            'clinic_id' => null,
            'doctor_user_id' => $this->doctor->id,
            'patient_profile_id' => $patient->id,
            'status' => 'pending',
        ]);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/appointments?from='.$scheduledAt->toDateString().'&to='.$scheduledAt->copy()->addDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $appointmentId)
            ->assertJsonPath('meta.summary.pending', 1);

        $updatedAt = $scheduledAt->copy()->addWeek()->setTime(11, 30);
        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/appointments/{$appointmentId}", [
                'scheduled_at' => $updatedAt->toIso8601String(),
                'notes' => 'Horario actualizado.',
            ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Horario actualizado.');

        $this->withSpaOrigin()
            ->postJson("/api/v1/auth/doctor/appointments/{$appointmentId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointmentId,
            'status' => 'canceled',
            'notes' => 'Horario actualizado.',
        ]);
    }

    public function test_independent_doctor_can_create_and_read_consultation_without_clinic(): void
    {
        $this->seed(ClinicalHistorySectionSeeder::class);
        $patient = $this->ownedPatient('+525510000407');
        $appointment = Appointment::factory()->create([
            'clinic_id' => null,
            'patient_profile_id' => $patient->id,
            'doctor_user_id' => $this->doctor->id,
            'scheduled_at' => now()->addDay()->setTime(12, 0),
        ]);

        $this->withSpaOrigin()
            ->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
                'appointment_id' => $appointment->id,
                'chief_complaint' => 'Dolor de cabeza persistente.',
                'sections' => [
                    'record_metadata' => ['unit' => 'Consultorio privado'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.clinic', null)
            ->assertJsonPath('data.clinical_history.sections.record_metadata.unit', 'Consultorio privado');

        $this->withSpaOrigin()
            ->getJson("/api/v1/auth/doctor/patients/{$patient->id}/clinical-history")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.clinic', null)
            ->assertJsonPath('data.0.patient_profile_id', $patient->id);
    }

    public function test_independent_doctor_can_read_and_update_preferences(): void
    {
        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/preferences')
            ->assertOk()
            ->assertJsonPath('data.working_hours.monday.start', '08:00')
            ->assertJsonPath('data.appointment_duration_minutes', 30);

        $this->withSpaOrigin()
            ->patchJson('/api/v1/auth/doctor/preferences', [
                'preferences' => [
                    'working_hours' => [
                        'monday' => ['enabled' => true, 'start' => '09:00', 'end' => '17:00'],
                    ],
                    'appointment_duration_minutes' => 45,
                    'buffer_minutes' => 15,
                    'auto_confirm_appointments' => true,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.working_hours.monday.start', '09:00')
            ->assertJsonPath('data.working_hours.tuesday.start', '08:00')
            ->assertJsonPath('data.appointment_duration_minutes', 45)
            ->assertJsonPath('data.buffer_minutes', 15)
            ->assertJsonPath('data.auto_confirm_appointments', true);

        $profile = DoctorProfile::query()->where('user_id', $this->doctor->id)->sole();
        $this->assertSame('09:00', $profile->preferences['working_hours']['monday']['start']);
        $this->assertSame(45, $profile->preferences['appointment_duration_minutes']);
    }

    public function test_availability_uses_requested_date_and_marks_conflicts(): void
    {
        $this->withSpaOrigin()->patchJson('/api/v1/auth/doctor/preferences', [
            'preferences' => [
                'working_hours' => [
                    'monday' => ['enabled' => true, 'start' => '09:00', 'end' => '11:00'],
                ],
                'appointment_duration_minutes' => 30,
                'buffer_minutes' => 0,
            ],
        ])->assertOk();

        $patient = $this->ownedPatient('+525510000408');
        Appointment::query()->create([
            'clinic_id' => null,
            'patient_profile_id' => $patient->id,
            'doctor_user_id' => $this->doctor->id,
            'scheduled_at' => '2030-01-07 09:30:00',
            'status' => 'confirmed',
            'appointment_type' => 'control',
        ]);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/availability?date=2030-01-07')
            ->assertOk()
            ->assertJsonPath('data.date', '2030-01-07')
            ->assertJsonPath('data.weekday', 'monday')
            ->assertJsonPath('data.working_hours.start', '09:00')
            ->assertJsonPath('data.slots.0.time', '09:00')
            ->assertJsonPath('data.slots.0.available', true)
            ->assertJsonPath('data.slots.1.time', '09:30')
            ->assertJsonPath('data.slots.1.available', false)
            ->assertJsonPath('data.slots.1.reason', 'conflict');
    }

    public function test_independent_doctor_rejects_conflicting_appointments_and_enforces_status_transitions(): void
    {
        $patient = $this->ownedPatient('+525510000409');
        $appointment = Appointment::query()->create([
            'clinic_id' => null,
            'patient_profile_id' => $patient->id,
            'doctor_user_id' => $this->doctor->id,
            'scheduled_at' => '2030-01-08 10:00:00',
            'status' => 'pending',
            'appointment_type' => 'control',
        ]);

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/doctor/appointments', [
                'patient_profile_id' => $patient->id,
                'scheduled_at' => '2030-01-08T10:15:00Z',
                'appointment_type' => 'consultation',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');

        foreach (['confirmed', 'waiting', 'in_consultation', 'completed'] as $status) {
            $this->withSpaOrigin()
                ->patchJson("/api/v1/auth/doctor/appointments/{$appointment->id}/status", ['status' => $status])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
        }

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/appointments/{$appointment->id}/status", ['status' => 'canceled'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_independent_doctor_rejects_appointments_inside_the_buffer_window(): void
    {
        $this->withSpaOrigin()->patchJson('/api/v1/auth/doctor/preferences', [
            'preferences' => [
                'buffer_minutes' => 15,
            ],
        ])->assertOk();

        $patient = $this->ownedPatient('+525510000412');
        Appointment::query()->create([
            'clinic_id' => null,
            'patient_profile_id' => $patient->id,
            'doctor_user_id' => $this->doctor->id,
            'scheduled_at' => '2030-01-08 10:35:00',
            'status' => 'confirmed',
            'appointment_type' => 'control',
        ]);

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/doctor/appointments', [
                'patient_profile_id' => $patient->id,
                'scheduled_at' => '2030-01-08T10:00:00Z',
                'appointment_type' => 'consultation',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');
    }

    public function test_consultation_signature_is_server_derived_and_signed_consultations_are_immutable(): void
    {
        $patient = $this->ownedPatient('+525510000410');

        $this->withSpaOrigin()
            ->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
                'chief_complaint' => 'Intento de firma suplantada.',
                'signature' => ['signed_by' => User::factory()->create()->id, 'signed_at' => now()->toIso8601String()],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('signature');

        $this->withSpaOrigin()
            ->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
                'chief_complaint' => 'Consulta que será completada y firmada.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.signature.signed_by', null);

        $consultation = Consultation::query()->sole();

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/consultations/{$consultation->id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/consultations/{$consultation->id}", ['status' => 'signed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'signed')
            ->assertJsonPath('data.signature.signed_by.id', $this->doctor->id);

        $this->assertDatabaseHas('consultations', [
            'id' => $consultation->id,
            'signed_by_user_id' => $this->doctor->id,
        ]);

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/doctor/consultations/{$consultation->id}", ['chief_complaint' => 'Cambio no autorizado.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseMissing('consultations', ['chief_complaint' => 'Cambio no autorizado.']);
    }

    public function test_doctor_dashboard_is_scoped_to_the_authenticated_independent_doctor(): void
    {
        $patient = $this->ownedPatient('+525510000411');
        $date = now()->addDay()->setTime(10, 0);
        Appointment::query()->create([
            'clinic_id' => null,
            'patient_profile_id' => $patient->id,
            'doctor_user_id' => $this->doctor->id,
            'scheduled_at' => $date,
            'status' => 'confirmed',
            'appointment_type' => 'control',
        ]);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/dashboard?date='.$date->toDateString())
            ->assertOk()
            ->assertJsonPath('data.doctor.id', $this->doctor->id)
            ->assertJsonPath('data.doctor.practice_type', 'independent')
            ->assertJsonPath('data.doctor.clinic', null)
            ->assertJsonPath('data.metrics.today_appointments', 1)
            ->assertJsonPath('data.metrics.patients_total', 1)
            ->assertJsonPath('data.today_appointments.0.doctor.id', $this->doctor->id)
            ->assertJsonPath('data.frequent_patients.0.id', $patient->id);
    }

    private function createIndependentDoctor(string $phone): User
    {
        $role = Role::query()->firstOrCreate(
            ['key' => RoleKey::Doctor->value],
            [
                'name' => 'Médico',
                'scope' => RoleScope::Organization,
                'is_system' => true,
            ],
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
            'plan_id' => $this->plan->id,
        ]);

        return $doctor;
    }

    private function ownedPatient(string $phone): PatientProfile
    {
        $user = User::factory()->create(['phone_number' => $phone]);

        return $user->patientProfile()->create([
            'clinic_id' => null,
            'owner_doctor_user_id' => $this->doctor->id,
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
