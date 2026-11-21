<?php

namespace Tests\Feature;

use App\Enums\ClinicMembershipStatus;
use App\Enums\ConsultationStatus;
use App\Enums\DoctorPracticeType;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\AdminAuditEvent;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicalHistoryRecord;
use App\Models\ClinicalHistorySection;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\Consultation;
use App\Models\DoctorProfile;
use App\Models\PatientIntake;
use App\Models\PatientProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Database\Seeders\ClinicalHistorySectionSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class DoctorClinicalRecordTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;

    private Clinic $otherClinic;

    private Plan $plan;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        $this->plan = Plan::factory()->create();
        $this->clinic = Clinic::factory()->create();
        $this->otherClinic = Clinic::factory()->create();
        ClinicSubscription::factory()->create(['clinic_id' => $this->clinic->id, 'plan_id' => $this->plan->id]);
        ClinicSubscription::factory()->create(['clinic_id' => $this->otherClinic->id, 'plan_id' => $this->plan->id]);

        $doctorRole = Role::query()->create(['key' => RoleKey::Doctor->value, 'name' => 'Médico', 'scope' => RoleScope::Organization, 'is_system' => true]);
        Role::query()->create(['key' => RoleKey::Receptionist->value, 'name' => 'Recepcionista', 'scope' => RoleScope::Organization, 'is_system' => true]);

        $this->doctor = User::factory()->create(['name' => 'Dra. Clara Villa', 'phone_number' => '+525510000301', 'password' => 'SecurePass123']);
        $this->doctor->roles()->attach($doctorRole);
        DoctorProfile::factory()->create(['user_id' => $this->doctor->id, 'clinic_id' => $this->clinic->id, 'practice_type' => DoctorPracticeType::Clinic]);
        ClinicMembership::query()->create(['clinic_id' => $this->clinic->id, 'user_id' => $this->doctor->id, 'role_id' => $doctorRole->id, 'status' => ClinicMembershipStatus::Active]);

        $this->seed(SpecialtySeeder::class);
        $this->seed(ClinicalHistorySectionSeeder::class);
        $this->authenticate();
    }

    public function test_section_catalog_seeds_15_sections_idempotently(): void
    {
        $this->seed(ClinicalHistorySectionSeeder::class);
        $this->seed(ClinicalHistorySectionSeeder::class);

        $this->assertDatabaseCount('clinical_history_sections', 15);
        $keys = ClinicalHistorySection::query()->pluck('key')->sort()->values()->all();

        $this->assertSame(['clinician_identification', 'diagnoses_problems', 'family_history', 'gyneco_obstetric_history', 'labs_imaging', 'non_pathological_history', 'pathological_history', 'patient_identification', 'pharmacological_treatment', 'physical_examination', 'present_illness', 'prognosis', 'record_metadata', 'systems_review', 'vital_signs'], $keys);
        $this->assertSame(15, ClinicalHistorySection::query()->where('is_active', true)->max('sort_order'));
    }

    public function test_doctor_creates_consultation_with_all_15_sections(): void
    {
        $patient = $this->connectedPatient('+525510000311');

        $response = $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
            'appointment_id' => null,
            'chief_complaint' => 'Paciente acude para control de hipertensión arterial.',
            'objective' => 'Evaluar control tensional y adherencia al tratamiento.',
            'sections' => $this->sectionsPayload(),
        ])->assertCreated()->assertJsonPath('data.status', 'in_progress');

        $this->assertSame($patient->id, $response->json('data.patient.id'));
        $this->assertDatabaseCount('consultations', 1);
        $this->assertDatabaseCount('clinical_history_records', 1);
        $this->assertDatabaseCount('clinical_history_entries', 15);
        $this->assertDatabaseHas('consultations', [
            'clinic_id' => $this->clinic->id,
            'doctor_user_id' => $this->doctor->id,
            'patient_profile_id' => $patient->id,
            'chief_complaint' => 'Paciente acude para control de hipertensión arterial.',
            'objective' => 'Evaluar control tensional y adherencia al tratamiento.',
        ]);

        $record = ClinicalHistorySection::query()->where('key', 'vital_signs')->sole();
        $this->assertDatabaseHas('clinical_history_entries', [
            'clinical_history_section_id' => $record->id,
        ]);
    }

    public function test_doctor_reads_patient_clinical_history(): void
    {
        $patient = $this->connectedPatient('+525510000312');
        PatientIntake::query()->create([
            'clinic_id' => $this->clinic->id,
            'patient_profile_id' => $patient->id,
            'registered_by_user_id' => $this->doctor->id,
            'requested_doctor_user_id' => $this->doctor->id,
            'objective' => 'Evaluar dolor torácico de inicio reciente.',
        ]);
        $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
            'chief_complaint' => 'Consulta de seguimiento ambulatorio.',
            'sections' => $this->sectionsPayload(),
        ])->assertCreated();

        $this->withSpaOrigin()->getJson("/api/v1/auth/doctor/patients/{$patient->id}/clinical-history")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient_profile_id', $patient->id)
            ->assertJsonPath('data.0.sections.0.section_key', 'record_metadata')
            ->assertJsonPath('data.0.objective', 'Evaluar dolor torácico de inicio reciente.');
    }

    public function test_doctor_patients_list_only_returns_connected_patients(): void
    {
        $patient = $this->connectedPatient('+525510000313');
        $unrelated = $this->patientInClinic($this->clinic, '+525510000314');

        $this->withSpaOrigin()->getJson('/api/v1/auth/doctor/patients')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $patient->id);

        PatientIntake::query()->create([
            'clinic_id' => $this->clinic->id,
            'patient_profile_id' => $unrelated->id,
            'registered_by_user_id' => $this->doctor->id,
            'requested_doctor_user_id' => $this->doctor->id,
            'specialty_id' => Specialty::query()->where('code', 'medicina_interna')->sole()->id,
            'objective' => 'Evaluar cefalea de dos semanas de evolución.',
        ]);

        $this->withSpaOrigin()->getJson('/api/v1/auth/doctor/patients')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_objective_propagates_from_intake_when_not_provided(): void
    {
        $patient = $this->connectedPatient('+525510000315');
        PatientIntake::query()->create([
            'clinic_id' => $this->clinic->id,
            'patient_profile_id' => $patient->id,
            'registered_by_user_id' => $this->doctor->id,
            'requested_doctor_user_id' => $this->doctor->id,
            'objective' => 'Evaluar dolor torácico de inicio reciente.',
        ]);

        $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
            'chief_complaint' => 'Dolor torácico precedido por esfuerzo físico.',
            'sections' => $this->sectionsPayload(),
        ])->assertCreated()->assertJsonPath('data.objective', 'Evaluar dolor torácico de inicio reciente.');

        $this->assertDatabaseHas('clinical_history_records', [
            'patient_profile_id' => $patient->id,
            'objective' => 'Evaluar dolor torácico de inicio reciente.',
        ]);
    }

    public function test_explicit_objective_overrides_intake(): void
    {
        $patient = $this->connectedPatient('+525510000316');
        PatientIntake::query()->create([
            'clinic_id' => $this->clinic->id,
            'patient_profile_id' => $patient->id,
            'registered_by_user_id' => $this->doctor->id,
            'requested_doctor_user_id' => $this->doctor->id,
            'objective' => 'Objetivo de la admisión clínica.',
        ]);

        $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
            'chief_complaint' => 'Paciente acude por recomendación de su médico de cabecera.',
            'objective' => 'Objetivo explícito de la consulta.',
            'sections' => $this->sectionsPayload(),
        ])->assertCreated()->assertJsonPath('data.objective', 'Objetivo explícito de la consulta.');
    }

    public function test_objective_respects_the_backend_length_limit_when_provided(): void
    {
        $patient = $this->connectedPatient('+525510000317');

        $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
            'chief_complaint' => 'Consulta con objetivo demasiado extenso.',
            'objective' => str_repeat('x', 2001),
        ])->assertUnprocessable()->assertJsonValidationErrors('objective');

        $this->assertDatabaseCount('consultations', 0);
    }

    public function test_fhir_blocks_another_doctors_appointment_in_the_same_clinic(): void
    {
        $unrelated = $this->patientInClinic($this->clinic, '+525510000360');
        $otherDoctor = $this->clinicDoctor('+525510000361');
        $appointment = Appointment::factory()->create([
            'clinic_id' => $this->clinic->id,
            'patient_profile_id' => $unrelated->id,
            'doctor_user_id' => $otherDoctor->id,
        ]);

        $this->withSpaOrigin()->getJson("/api/v1/fhir/Patient/{$unrelated->id}")
            ->assertNotFound();
        $this->withSpaOrigin()->getJson("/api/v1/fhir/Appointment/{$appointment->id}")
            ->assertNotFound();
    }

    public function test_fhir_revokes_patient_appointment_and_organization_after_doctor_suspension(): void
    {
        $patient = $this->connectedPatient('+525510000362');
        $appointment = Appointment::query()->where('patient_profile_id', $patient->id)->sole();

        $this->withSpaOrigin()->getJson("/api/v1/fhir/Patient/{$patient->id}")
            ->assertOk();
        $this->withSpaOrigin()->getJson("/api/v1/fhir/Appointment/{$appointment->id}")
            ->assertOk();

        ClinicMembership::query()
            ->where('user_id', $this->doctor->id)
            ->where('clinic_id', $this->clinic->id)
            ->update(['status' => ClinicMembershipStatus::Suspended->value]);

        // The session may remain valid, but clinical access must be revoked immediately.
        $this->withSpaOrigin()->getJson("/api/v1/fhir/Patient/{$patient->id}")
            ->assertNotFound();
        $this->withSpaOrigin()->getJson("/api/v1/fhir/Appointment/{$appointment->id}")
            ->assertNotFound();
        $this->withSpaOrigin()->getJson("/api/v1/fhir/Organization/{$this->clinic->id}")
            ->assertNotFound();
    }

    public function test_rejects_cross_clinic_patient_access(): void
    {
        $otherPatient = $this->patientInClinic($this->otherClinic, '+525510000317');

        $this->withSpaOrigin()->getJson("/api/v1/auth/doctor/patients/{$otherPatient->id}/clinical-history")
            ->assertNotFound();

        $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/patients/{$otherPatient->id}/consultations", [
            'chief_complaint' => 'Consulta sin vínculo clínico.',
            'sections' => $this->sectionsPayload(),
        ])->assertNotFound();

        $this->assertDatabaseCount('consultations', 0);
    }

    public function test_rejects_consultation_for_unconnected_patient_in_clinic(): void
    {
        $unrelated = $this->patientInClinic($this->clinic, '+525510000318');

        $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/patients/{$unrelated->id}/consultations", [
            'chief_complaint' => 'Consulta sin vínculo clínico con el médico.',
            'sections' => $this->sectionsPayload(),
        ])->assertForbidden();

        $this->assertDatabaseCount('consultations', 0);
    }

    public function test_rejects_consultation_from_cross_clinic_doctor(): void
    {
        $patient = $this->connectedPatient('+525510000319');
        $otherDoctorRole = Role::query()->where('key', RoleKey::Doctor->value)->sole();
        $otherDoctor = User::factory()->create(['name' => 'Dr. Otro', 'phone_number' => '+525510000320', 'password' => 'SecurePass123']);
        $otherDoctor->roles()->attach($otherDoctorRole);
        DoctorProfile::factory()->create(['user_id' => $otherDoctor->id, 'clinic_id' => $this->otherClinic->id, 'practice_type' => DoctorPracticeType::Clinic]);
        ClinicMembership::query()->create(['clinic_id' => $this->otherClinic->id, 'user_id' => $otherDoctor->id, 'role_id' => $otherDoctorRole->id, 'status' => ClinicMembershipStatus::Active]);

        $this->authenticateAs($otherDoctor);

        $this->withSpaOrigin()->getJson("/api/v1/auth/doctor/patients/{$patient->id}/clinical-history")
            ->assertNotFound();
        $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
            'chief_complaint' => 'Consulta desde otra clínica.',
            'sections' => $this->sectionsPayload(),
        ])->assertNotFound();

        $this->assertDatabaseCount('consultations', 0);
    }

    public function test_rejects_consultation_read_by_another_doctor(): void
    {
        $patient = $this->connectedPatient('+525510000321');
        $record = ClinicalHistoryRecord::query()->create([
            'clinic_id' => $this->clinic->id,
            'patient_profile_id' => $patient->id,
            'doctor_user_id' => $this->doctor->id,
            'created_by_user_id' => $this->doctor->id,
            'source' => 'consultation',
            'objective' => 'Evaluar dolor torácico de inicio reciente.',
        ]);
        $consultation = Consultation::query()->create([
            'clinic_id' => $this->clinic->id,
            'doctor_user_id' => $this->doctor->id,
            'patient_profile_id' => $patient->id,
            'clinical_history_record_id' => $record->id,
            'created_by_user_id' => $this->doctor->id,
            'status' => ConsultationStatus::InProgress->value,
            'chief_complaint' => 'Consulta de gastroenterología ambulatoria.',
            'objective' => 'Evaluar dolor torácico de inicio reciente.',
        ]);

        $otherDoctor = $this->clinicDoctor('+525510000322');
        $this->authenticateAs($otherDoctor);

        $this->withSpaOrigin()->getJson("/api/v1/auth/doctor/consultations/{$consultation->id}")
            ->assertNotFound();
    }

    public function test_validation_rejects_unknown_section_and_rolls_back(): void
    {
        $patient = $this->connectedPatient('+525510000323');

        $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
            'chief_complaint' => 'Consulta con sección inválida.',
            'sections' => ['unknown_section' => ['value' => 'no']],
        ])->assertUnprocessable()->assertJsonValidationErrors('sections');

        $this->assertDatabaseCount('consultations', 0);
        $this->assertDatabaseCount('clinical_history_records', 0);
        $this->assertDatabaseCount('clinical_history_entries', 0);
    }

    public function test_update_consultation_preserves_unspecified_sections_and_fields(): void
    {
        $patient = $this->connectedPatient('+525510000324');
        $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
            'chief_complaint' => 'Primera versión del motivo de consulta.',
            'sections' => $this->sectionsPayload(),
        ])->assertCreated();
        $consultationId = Consultation::query()->sole()->id;

        $this->withSpaOrigin()->patchJson("/api/v1/auth/doctor/consultations/{$consultationId}", [
            'chief_complaint' => 'Motivo de consulta actualizado.',
            'status' => ConsultationStatus::Completed->value,
            'sections' => ['record_metadata' => ['unit' => 'Unidad X']],
        ])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.clinical_history.sections.record_metadata.unit', 'Unidad X');

        $this->assertDatabaseHas('consultations', ['id' => $consultationId, 'status' => 'completed']);
        $this->assertDatabaseCount('clinical_history_entries', 15);
        $this->withSpaOrigin()->getJson("/api/v1/auth/doctor/consultations/{$consultationId}")
            ->assertOk()->assertJsonPath('data.clinical_history.sections.record_metadata.description', 'Valor de record_metadata');
    }

    public function test_appointment_encounter_completion_signature_and_audit_are_consistent(): void
    {
        $patient = $this->connectedPatient('+525510000340');
        $appointment = Appointment::query()->sole();
        $appointment->update(['status' => 'waiting']);
        $this->doctor->doctorProfile->update(['medical_license' => '1234567', 'medical_license_country_code' => 'MX']);
        $payload = ['appointment_id' => $appointment->id, 'chief_complaint' => 'Consulta de seguimiento.', 'sections' => $this->sectionsPayload()];
        $created = $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", $payload)->assertCreated();
        $id = $created->json('data.id');
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'in_consultation']);
        $this->assertDatabaseHas('clinical_history_records', ['appointment_id' => $appointment->id]);
        $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/appointments/{$appointment->id}/cancel")->assertUnprocessable();
        $this->withSpaOrigin()->patchJson("/api/v1/auth/doctor/appointments/{$appointment->id}/status", ['status' => 'completed'])->assertUnprocessable();
        $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('appointment_id');
        $this->assertDatabaseCount('clinical_history_records', 1);
        $this->assertDatabaseCount('admin_audit_events', 1);
        $this->withSpaOrigin()->patchJson("/api/v1/auth/doctor/consultations/{$id}", ['status' => 'completed', 'sections' => null])
            ->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseCount('clinical_history_entries', 15);
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'completed']);
        $this->withSpaOrigin()->patchJson("/api/v1/auth/doctor/consultations/{$id}", ['status' => 'signed'])
            ->assertOk()->assertJsonPath('data.status', 'signed');
        $digest = Consultation::query()->findOrFail($id)->signature_digest;
        $this->assertNotEmpty($digest);
        $this->withSpaOrigin()->patchJson("/api/v1/auth/doctor/consultations/{$id}", ['chief_complaint' => 'Cambio posterior a firma.'])
            ->assertUnprocessable();
        $this->assertSame($digest, Consultation::query()->findOrFail($id)->signature_digest);
        $this->assertDatabaseCount('admin_audit_events', 3);
        $event = AdminAuditEvent::query()->latest('id')->first();
        $this->assertSame(['previous_status' => 'completed', 'status' => 'signed'], $event->metadata);
    }

    public function test_prepared_draft_allows_arrival_then_doctor_starts_attention(): void
    {
        $patient = $this->connectedPatient('+525510000343');
        $appointment = Appointment::query()->sole();
        $appointment->update(['status' => 'confirmed']);
        $id = $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
            'appointment_id' => $appointment->id, 'chief_complaint' => 'Consulta de seguimiento.', 'status' => 'draft',
        ])->assertCreated()->json('data.id');
        $this->withSpaOrigin()->patchJson("/api/v1/auth/doctor/appointments/{$appointment->id}/status", ['status' => 'waiting'])->assertOk();
        $this->withSpaOrigin()->patchJson("/api/v1/auth/doctor/consultations/{$id}", ['status' => 'in_progress'])->assertOk();
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'in_consultation']);
    }

    public function test_closed_appointments_reject_consultations_without_orphan_records(): void
    {
        $patient = $this->connectedPatient('+525510000341');
        $appointment = Appointment::query()->sole();
        foreach (['canceled', 'no_show', 'completed'] as $status) {
            $appointment->update(['status' => $status]);
            $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
                'appointment_id' => $appointment->id, 'chief_complaint' => 'Consulta de seguimiento.',
            ])->assertUnprocessable()->assertJsonValidationErrors('appointment_id');
        }
        $this->assertDatabaseCount('clinical_history_records', 0);
        $this->assertDatabaseCount('consultations', 0);
        $this->assertDatabaseCount('admin_audit_events', 0);
    }

    public function test_appointment_link_cannot_be_detached_and_canceled_consultation_is_immutable(): void
    {
        $patient = $this->connectedPatient('+525510000342');
        $appointment = Appointment::query()->sole();
        $appointment->update(['status' => 'waiting']);
        $created = $this->withSpaOrigin()->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [
            'appointment_id' => $appointment->id, 'chief_complaint' => 'Consulta de seguimiento.', 'status' => 'draft',
        ])->assertCreated();
        $id = $created->json('data.id');
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'waiting']);
        $this->withSpaOrigin()->patchJson("/api/v1/auth/doctor/consultations/{$id}", ['appointment_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('appointment_id');
        $this->assertDatabaseHas('clinical_history_records', ['appointment_id' => $appointment->id]);
        $this->withSpaOrigin()->patchJson("/api/v1/auth/doctor/consultations/{$id}", ['status' => 'canceled'])->assertOk();
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'canceled']);
        $this->withSpaOrigin()->patchJson("/api/v1/auth/doctor/consultations/{$id}", ['chief_complaint' => 'Cambio tras cancelar.'])->assertUnprocessable();
    }

    public function test_unauthenticated_doctor_routes_are_rejected(): void
    {
        $patient = $this->patientInClinic($this->clinic, '+525510000333');
        $this->defaultCookies = [];
        $this->actingAsGuest();

        $this->getJson('/api/v1/auth/doctor/patients')->assertUnauthorized();
        $this->getJson("/api/v1/auth/doctor/patients/{$patient->id}/clinical-history")->assertUnauthorized();
        $this->postJson("/api/v1/auth/doctor/patients/{$patient->id}/consultations", [])->assertUnauthorized();
    }

    /** @return array<string, array<string, string>> */
    private function sectionsPayload(): array
    {
        $sections = [];

        foreach (ClinicalHistorySection::query()->where('is_active', true)->orderBy('sort_order')->get() as $section) {
            $sections[$section->key] = ['description' => 'Valor de '.$section->key];
        }

        return $sections;
    }

    private function connectedPatient(string $phone): PatientProfile
    {
        $patient = $this->patientInClinic($this->clinic, $phone);
        Appointment::factory()->create([
            'clinic_id' => $this->clinic->id,
            'patient_profile_id' => $patient->id,
            'doctor_user_id' => $this->doctor->id,
        ]);

        return $patient;
    }

    private function patientInClinic(Clinic $clinic, string $phone): PatientProfile
    {
        $user = User::factory()->create(['name' => fake()->firstName().' '.fake()->lastName(), 'phone_number' => $phone]);

        return $user->patientProfile()->create(['clinic_id' => $clinic->id]);
    }

    private function authenticate(): void
    {
        $this->authenticateAs($this->doctor);
    }

    private function authenticateAs(User $user): void
    {
        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => $this->phoneNumber($user->phone_number),
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

    private function clinicDoctor(string $phone): User
    {
        $doctorRole = Role::query()->where('key', RoleKey::Doctor->value)->sole();
        $doctor = User::factory()->create(['name' => 'Dr. '.fake()->lastName(), 'phone_number' => $phone, 'password' => 'SecurePass123']);
        $doctor->roles()->attach($doctorRole);
        DoctorProfile::factory()->create(['user_id' => $doctor->id, 'clinic_id' => $this->clinic->id, 'practice_type' => DoctorPracticeType::Clinic]);
        ClinicMembership::query()->create(['clinic_id' => $this->clinic->id, 'user_id' => $doctor->id, 'role_id' => $doctorRole->id, 'status' => ClinicMembershipStatus::Active]);

        return $doctor;
    }

    private function phoneNumber(string $value): string
    {
        // Phone numbers are stored in E.164 (+52...); login accepts the national format.
        return preg_replace('/^\+52/', '', $value);
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
