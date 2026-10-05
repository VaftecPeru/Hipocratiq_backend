<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\ConsultationStatus;
use App\Enums\LaboratoryOrderStatus;
use App\Enums\PrescriptionStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicalHistoryRecord;
use App\Models\ClinicSubscription;
use App\Models\Consultation;
use App\Models\DoctorPrescription;
use App\Models\LaboratoryOrder;
use App\Models\PatientIntakeDraft;
use App\Models\PatientProfile;
use App\Models\Plan;
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

    public function test_patient_history_excludes_drafts_in_progress_and_canceled_consultations(): void
    {
        $doctor = User::factory()->create();
        foreach (ConsultationStatus::cases() as $status) {
            Consultation::query()->create([
                'clinic_id' => $this->clinic->id, 'patient_profile_id' => $this->patient->id,
                'doctor_user_id' => $doctor->id, 'status' => $status,
                'chief_complaint' => 'Registro de prueba '.$status->value,
            ]);
        }
        $response = $this->withSpaOrigin()->getJson('/api/v1/auth/patient/history')->assertOk()->assertJsonCount(2, 'data');
        $statuses = collect($response->json('data'))->pluck('status')->sort()->values()->all();
        $this->assertSame(['completed', 'signed'], $statuses);
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
            'triage_level' => 'IV',
            'triage_status' => 'submitted',
            'triage_notes' => 'Información registrada por el paciente.',
        ])->assertOk()->assertJsonPath('data.reason', 'Dolor de garganta persistente.');

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
            'triage_level' => 'IV',
            'triage_status' => 'submitted',
            'oxygen_saturation' => 97,
        ]);
        $this->withSpaOrigin()->getJson('/api/v1/auth/patient/triage')
            ->assertOk()
            ->assertJsonPath('data.reason', 'Dolor de garganta persistente.')
            ->assertJsonPath('data.triage_level', 'IV')
            ->assertJsonPath('data.triage_status', 'submitted')
            ->assertJsonPath('data.recorded_by.id', $this->patientUser->id);
    }

    public function test_patient_can_save_triage_draft_and_submit_the_same_record(): void
    {
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/patient/triage', [
            'reason' => 'Malestar general con evolución de dos días.',
            'temperature_c' => 37.1,
            'triage_level' => 'III',
            'triage_status' => 'draft',
            'triage_notes' => 'Pendiente completar signos vitales.',
        ])->assertOk()
            ->assertJsonPath('data.triage_level', 'III')
            ->assertJsonPath('data.triage_status', 'draft')
            ->assertJsonPath('meta.next_step', 'draft_saved');

        $triageId = $draft->json('data.id');

        $this->withSpaOrigin()->postJson('/api/v1/auth/patient/triage', [
            'reason' => 'Malestar general con evolución de dos días.',
            'temperature_c' => 37.3,
            'systolic_bp' => 118,
            'diastolic_bp' => 76,
            'heart_rate' => 84,
            'respiratory_rate' => 18,
            'oxygen_saturation' => 98,
            'triage_level' => 'III',
            'triage_status' => 'submitted',
            'triage_notes' => 'Formulario completado por el paciente.',
        ])->assertOk()
            ->assertJsonPath('data.id', $triageId)
            ->assertJsonPath('data.triage_priority', 'urgent')
            ->assertJsonPath('data.triage_status', 'submitted')
            ->assertJsonPath('meta.next_step', 'submitted');

        $this->assertDatabaseCount('patient_intakes', 1);
        $this->assertDatabaseHas('patient_intakes', [
            'id' => $triageId,
            'triage_level' => 'III',
            'triage_status' => 'submitted',
            'triage_priority' => 'urgent',
        ]);
    }

    public function test_patient_can_save_and_reload_only_their_current_clinic_intake_draft(): void
    {
        $otherUser = User::factory()->create(['name' => 'Otro paciente']);
        $otherPatient = $otherUser->patientProfile()->create(['clinic_id' => $this->clinic->id]);
        $otherClinic = Clinic::factory()->create();

        PatientIntakeDraft::query()->create([
            'patient_profile_id' => $otherPatient->id,
            'clinic_id' => $this->clinic->id,
            'sections' => ['patient_identification' => ['name' => 'Datos de otra persona']],
        ]);
        PatientIntakeDraft::query()->create([
            'patient_profile_id' => $this->patient->id,
            'clinic_id' => $otherClinic->id,
            'sections' => ['patient_identification' => ['name' => 'Datos de otra clínica']],
        ]);

        $payload = $this->validIntakePayload();
        $response = $this->withSpaOrigin()->patchJson('/api/v1/auth/patient/intake-draft', $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.sections.patient_identification.name', 'Paciente de prueba')
            ->assertJsonPath('meta.patient_owned', true)
            ->assertJsonMissingPath('data.sections.physical_examination');

        $draftId = $response->json('data.id');
        $acceptedAt = $response->json('data.declaration_accepted_at');

        self::assertIsInt($draftId);
        self::assertNotNull($acceptedAt);
        $this->assertDatabaseHas('patient_intake_drafts', [
            'id' => $draftId,
            'patient_profile_id' => $this->patient->id,
            'clinic_id' => $this->clinic->id,
        ]);

        $this->withSpaOrigin()->getJson('/api/v1/auth/patient/intake-draft')
            ->assertOk()
            ->assertJsonPath('data.id', $draftId)
            ->assertJsonPath('data.declaration_accepted_at', $acceptedAt)
            ->assertJsonPath('data.sections.patient_identification.name', 'Paciente de prueba')
            ->assertJsonMissing(['name' => 'Datos de otra persona'])
            ->assertJsonMissing(['name' => 'Datos de otra clínica']);

        $storedDraft = PatientIntakeDraft::query()->findOrFail($draftId);
        self::assertArrayNotHasKey('physical_examination', $storedDraft->sections);
    }

    public function test_patient_intake_rejects_clinician_sections_and_invalid_vital_signs(): void
    {
        $payload = $this->validIntakePayload();
        $payload['declaration_accepted'] = false;

        $this->withSpaOrigin()->patchJson('/api/v1/auth/patient/intake-draft', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['declaration_accepted']);

        $payload = $this->validIntakePayload();
        $payload['sections']['physical_examination'] = ['findings' => 'No debe aceptarse'];

        $this->withSpaOrigin()->patchJson('/api/v1/auth/patient/intake-draft', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sections']);

        $payload = $this->validIntakePayload();
        $payload['sections']['vital_signs']['blood_pressure_systolic'] = 100;
        $payload['sections']['vital_signs']['blood_pressure_diastolic'] = 110;

        $this->withSpaOrigin()->patchJson('/api/v1/auth/patient/intake-draft', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sections.vital_signs.blood_pressure_diastolic']);

        $this->assertDatabaseCount('patient_intake_drafts', 0);
    }

    public function test_patient_intake_preserves_the_first_declaration_timestamp(): void
    {
        $payload = $this->validIntakePayload();
        $firstResponse = $this->withSpaOrigin()->patchJson('/api/v1/auth/patient/intake-draft', $payload)
            ->assertOk();
        $acceptedAt = $firstResponse->json('data.declaration_accepted_at');

        $this->withSpaOrigin()->patchJson('/api/v1/auth/patient/intake-draft', $payload)
            ->assertOk()
            ->assertJsonPath('data.declaration_accepted_at', $acceptedAt);

        $this->assertDatabaseCount('patient_intake_drafts', 1);
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
            'triage_level' => 'VI',
            'triage_status' => 'submitted',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'diastolic_bp', 'triage_level']);

        $this->assertDatabaseCount('patient_intakes', 0);
    }

    public function test_patient_membership_and_epidemiology_return_real_safe_data(): void
    {
        $plan = Plan::factory()->create([
            'key' => 'professional',
            'name' => 'Professional',
            'features' => ['Agenda', 'Expediente clínico', 'Resultados'],
        ]);
        ClinicSubscription::factory()->create([
            'clinic_id' => $this->clinic->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->withSpaOrigin()->getJson('/api/v1/auth/patient/membership')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.source', 'clinic')
            ->assertJsonPath('data.plan.name', 'Professional')
            ->assertJsonPath('data.subscription.status', 'active');

        $this->withSpaOrigin()->getJson('/api/v1/auth/patient/epidemiology')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.participation.consent_active', false)
            ->assertJsonPath('data.metrics.appointments', 0)
            ->assertJsonCount(3, 'data.trend');
    }

    public function test_patient_routes_require_authentication(): void
    {
        $this->withCookie((string) config('session.cookie'), '')->withSpaOrigin()
            ->getJson('/api/v1/auth/patient/profile')
            ->assertUnauthorized();
        $this->withCookie((string) config('session.cookie'), '')->withSpaOrigin()
            ->getJson('/api/v1/auth/patient/intake-draft')
            ->assertUnauthorized();
        $this->withCookie((string) config('session.cookie'), '')->withSpaOrigin()
            ->patchJson('/api/v1/auth/patient/intake-draft', [])
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

    /** @return array<string, mixed> */
    private function validIntakePayload(): array
    {
        return [
            'declaration_accepted' => true,
            'sections' => [
                'patient_identification' => [
                    'name' => 'Paciente de prueba',
                    'gender' => 'Otro',
                ],
                'family_history' => ['description' => 'Sin antecedentes familiares informados.'],
                'non_pathological_history' => ['description' => 'Sin antecedentes no patológicos informados.'],
                'pathological_history' => ['description' => 'Sin antecedentes patológicos informados.'],
                'gyneco_obstetric_history' => ['description' => null],
                'present_illness' => [
                    'reason' => 'Consulta preventiva',
                    'illness_narrative' => 'Sin síntomas actuales informados.',
                ],
                'systems_review' => ['description' => 'Sin hallazgos autorreportados.'],
                'vital_signs' => [
                    'blood_pressure_systolic' => 120,
                    'blood_pressure_diastolic' => 80,
                    'temperature_c' => 36.5,
                    'heart_rate' => 72,
                    'respiratory_rate' => 16,
                    'oxygen_saturation' => 98,
                    'weight_kg' => 70.5,
                    'height_cm' => 175,
                    'notes' => 'Mediciones autorreportadas.',
                ],
            ],
        ];
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
