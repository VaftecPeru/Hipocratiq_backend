<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\ConsultationStatus;
use App\Enums\LaboratoryOrderStatus;
use App\Enums\PrescriptionStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicalHistoryRecord;
use App\Models\Consultation;
use App\Models\DoctorPrescription;
use App\Models\LaboratoryOrder;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class PatientPortalTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;

    private User $patientUser;

    private PatientProfile $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        $this->clinic = Clinic::factory()->create();
        $this->patientUser = User::factory()->create([
            'name' => 'Paciente autenticado',
            'phone_number' => '+525510000901',
            'password' => null,
        ]);
        $this->patient = $this->patientUser->patientProfile()->create([
            'clinic_id' => $this->clinic->id,
            'document_type' => 'DNI',
            'document_number' => '90000001',
            'sex' => 'Otro',
        ]);
        $this->authenticatePatient($this->patientUser);
    }

    public function test_patient_reads_only_own_profile_and_clinical_records(): void
    {
        $doctor = User::factory()->create(['name' => 'Médico vinculado']);
        $otherUser = User::factory()->create(['name' => 'Otro paciente']);
        $otherPatient = $otherUser->patientProfile()->create(['clinic_id' => $this->clinic->id]);
        $ownAppointment = Appointment::query()->create([
            'clinic_id' => $this->clinic->id,
            'patient_profile_id' => $this->patient->id,
            'doctor_user_id' => $doctor->id,
            'scheduled_at' => now()->addDay(),
            'status' => AppointmentStatus::Confirmed,
            'appointment_type' => 'consultation',
        ]);
        $otherAppointment = Appointment::query()->create([
            'clinic_id' => $this->clinic->id,
            'patient_profile_id' => $otherPatient->id,
            'doctor_user_id' => $doctor->id,
            'scheduled_at' => now()->addDays(2),
            'status' => AppointmentStatus::Confirmed,
            'appointment_type' => 'consultation',
        ]);
        $ownRecord = ClinicalHistoryRecord::query()->create([
            'clinic_id' => $this->clinic->id,
            'patient_profile_id' => $this->patient->id,
            'doctor_user_id' => $doctor->id,
            'created_by_user_id' => $doctor->id,
            'source' => 'consultation',
            'objective' => 'Consulta registrada para el paciente autenticado.',
        ]);
        Consultation::query()->create([
            'clinic_id' => $this->clinic->id,
            'doctor_user_id' => $doctor->id,
            'patient_profile_id' => $this->patient->id,
            'clinical_history_record_id' => $ownRecord->id,
            'status' => ConsultationStatus::Completed,
            'chief_complaint' => 'Motivo registrado en la consulta propia.',
            'objective' => 'Objetivo registrado en la consulta propia.',
            'completed_at' => now(),
        ]);
        $otherRecord = ClinicalHistoryRecord::query()->create([
            'clinic_id' => $this->clinic->id,
            'patient_profile_id' => $otherPatient->id,
            'doctor_user_id' => $doctor->id,
            'created_by_user_id' => $doctor->id,
            'source' => 'consultation',
            'objective' => 'Registro de otra persona.',
        ]);
        Consultation::query()->create([
            'clinic_id' => $this->clinic->id,
            'doctor_user_id' => $doctor->id,
            'patient_profile_id' => $otherPatient->id,
            'clinical_history_record_id' => $otherRecord->id,
            'status' => ConsultationStatus::Completed,
            'chief_complaint' => 'Consulta de otra persona.',
            'objective' => 'No debe aparecer.',
            'completed_at' => now(),
        ]);
        DoctorPrescription::query()->create([
            'clinic_id' => $this->clinic->id,
            'doctor_user_id' => $doctor->id,
            'patient_profile_id' => $this->patient->id,
            'medication' => 'Tratamiento propio',
            'status' => PrescriptionStatus::Active,
            'issued_at' => now(),
        ]);
        DoctorPrescription::query()->create([
            'clinic_id' => $this->clinic->id,
            'doctor_user_id' => $doctor->id,
            'patient_profile_id' => $otherPatient->id,
            'medication' => 'Tratamiento ajeno',
            'status' => PrescriptionStatus::Active,
            'issued_at' => now(),
        ]);
        LaboratoryOrder::query()->create([
            'clinic_id' => $this->clinic->id,
            'doctor_user_id' => $doctor->id,
            'patient_profile_id' => $this->patient->id,
            'study_name' => 'Estudio propio',
            'status' => LaboratoryOrderStatus::Ready,
            'ordered_at' => now(),
            'result_at' => now(),
            'result_summary' => 'Resultado propio registrado.',
        ]);
        LaboratoryOrder::query()->create([
            'clinic_id' => $this->clinic->id,
            'doctor_user_id' => $doctor->id,
            'patient_profile_id' => $otherPatient->id,
            'study_name' => 'Estudio ajeno',
            'status' => LaboratoryOrderStatus::Ready,
            'ordered_at' => now(),
            'result_at' => now(),
            'result_summary' => 'Resultado ajeno.',
        ]);

        $this->withSpaOrigin()->getJson('/api/v1/auth/patient/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $this->patient->id)
            ->assertJsonPath('data.name', 'Paciente autenticado')
            ->assertJsonMissingPath('data.other_patient');
        $this->withSpaOrigin()->getJson('/api/v1/auth/patient/appointments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownAppointment->id)
            ->assertJsonMissingPath('data.1');
        $this->withSpaOrigin()->getJson('/api/v1/auth/patient/history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient_profile_id', null)
            ->assertJsonPath('data.0.chief_complaint', 'Motivo registrado en la consulta propia.')
            ->assertJsonMissing(['chief_complaint' => 'Consulta de otra persona.']);
        $this->withSpaOrigin()->getJson('/api/v1/auth/patient/prescriptions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.medication', 'Tratamiento propio')
            ->assertJsonMissing(['medication' => 'Tratamiento ajeno']);
        $this->withSpaOrigin()->getJson('/api/v1/auth/patient/results')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.study_name', 'Estudio propio')
            ->assertJsonMissing(['study_name' => 'Estudio ajeno']);
    }

    public function test_patient_can_update_profile_and_persist_triage(): void
    {
        $this->withSpaOrigin()->patchJson('/api/v1/auth/patient/profile', [
            'name' => 'Paciente actualizado',
            'email' => 'patient-updated@example.test',
            'address' => 'Dirección registrada por el paciente',
            'whatsapp_consent' => true,
        ])->assertOk()->assertJsonPath('data.name', 'Paciente actualizado');

        $this->withSpaOrigin()->postJson('/api/v1/auth/patient/triage', [
            'reason' => 'Dolor de garganta persistente.',
            'temperature_c' => 37.2,
            'systolic_bp' => 118,
            'diastolic_bp' => 76,
            'heart_rate' => 82,
            'respiratory_rate' => 16,
            'oxygen_saturation' => 97,
            'triage_priority' => 'routine',
            'triage_notes' => 'Información registrada por el paciente.',
        ])->assertCreated()->assertJsonPath('data.reason', 'Dolor de garganta persistente.');

        $this->assertDatabaseHas('users', [
            'id' => $this->patientUser->id,
            'name' => 'Paciente actualizado',
            'email' => 'patient-updated@example.test',
        ]);
        $this->assertDatabaseHas('patient_intakes', [
            'patient_profile_id' => $this->patient->id,
            'registered_by_user_id' => $this->patientUser->id,
            'clinic_id' => $this->clinic->id,
            'triage_priority' => 'routine',
            'oxygen_saturation' => 97,
        ]);
        $this->withSpaOrigin()->getJson('/api/v1/auth/patient/triage')
            ->assertOk()
            ->assertJsonPath('data.reason', 'Dolor de garganta persistente.')
            ->assertJsonPath('data.recorded_by.id', $this->patientUser->id);
    }

    public function test_patient_rejects_invalid_profile_and_triage_payloads(): void
    {
        $this->withSpaOrigin()->patchJson('/api/v1/auth/patient/profile', [
            'email' => 'not-an-email',
            'country_code' => 'AR',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'country_code']);

        $this->withSpaOrigin()->postJson('/api/v1/auth/patient/triage', [
            'reason' => 'Corto',
            'systolic_bp' => 120,
            'diastolic_bp' => 120,
        ])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'diastolic_bp']);

        $this->assertDatabaseCount('patient_intakes', 0);
    }

    public function test_patient_routes_require_authentication_and_report_unavailable_sources_honestly(): void
    {
        $this->withSpaOrigin()->getJson('/api/v1/auth/patient/membership')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.available', false);
        $this->withSpaOrigin()->getJson('/api/v1/auth/patient/epidemiology')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.available', false);
        $this->withCookie((string) config('session.cookie'), '')->withSpaOrigin()
            ->getJson('/api/v1/auth/patient/profile')
            ->assertUnauthorized();
    }

    private function authenticatePatient(User $user): void
    {
        $challenge = $this->withSpaOrigin()->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'identifier' => $user->phone_number,
            'actor_context' => 'patient',
            'requested_role' => 'patient',
            'client_type' => 'web',
        ])->assertAccepted();
        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/otp/verify', [
            'purpose' => 'login',
            'challenge_id' => $challenge->json('data.challenge_id'),
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
