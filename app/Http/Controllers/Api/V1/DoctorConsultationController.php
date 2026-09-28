<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Actions\Clinical\RecordClinicalAuditEvent;
use App\Enums\AppointmentStatus;
use App\Enums\ConsultationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DoctorConsultationStoreRequest;
use App\Http\Requests\Api\V1\DoctorConsultationUpdateRequest;
use App\Http\Resources\Api\V1\ConsultationResource;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicalHistoryRecord;
use App\Models\ClinicalHistorySection;
use App\Models\Consultation;
use App\Models\PatientIntake;
use App\Models\PatientProfile;
use App\Models\User;
use App\Support\Clinical\DoctorClinicScope;
use App\Support\Clinical\DoctorScheduling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DoctorConsultationController extends Controller
{
    public function store(
        DoctorConsultationStoreRequest $request,
        PatientProfile $patient,
        DoctorScheduling $scheduling,
    ): JsonResponse {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $this->assertPatientConnected($doctor, $clinic, $patient);

        $data = $request->validated();

        $objective = $this->resolveObjective($data['objective'] ?? null, $clinic, $patient);
        $status = ConsultationStatus::from($data['status'] ?? ConsultationStatus::InProgress->value);
        $scheduling->assertConsultationStatusTransition(ConsultationStatus::Draft, $status);

        $consultation = DB::transaction(function () use ($doctor, $clinic, $patient, $data, $objective, $status, $request): Consultation {
            $appointment = isset($data['appointment_id'])
                ? $this->lockAppointment($doctor, $clinic, $patient, (int) $data['appointment_id'])
                : null;
            $this->syncAppointmentStatus($appointment, $status);
            $record = ClinicalHistoryRecord::query()->create([
                'clinic_id' => $clinic?->id,
                'patient_profile_id' => $patient->id,
                'doctor_user_id' => $doctor->id,
                'appointment_id' => $data['appointment_id'] ?? null,
                'created_by_user_id' => $doctor->id,
                'source' => 'consultation',
                'objective' => $objective,
            ]);

            $this->syncSections($record, $data['sections'] ?? []);

            $created = Consultation::query()->create([
                'clinic_id' => $clinic?->id,
                'doctor_user_id' => $doctor->id,
                'patient_profile_id' => $patient->id,
                'appointment_id' => $data['appointment_id'] ?? null,
                'clinical_history_record_id' => $record->id,
                'created_by_user_id' => $doctor->id,
                'status' => $status,
                'chief_complaint' => $data['chief_complaint'],
                'objective' => $objective,
                'completed_at' => $status === ConsultationStatus::Completed ? now() : null,
            ]);
            $this->syncCodings($created, $data['codings'] ?? [], $doctor);
            $this->recordAudit($request, $clinic, $created, null);

            return $created;
        });

        return (new ConsultationResource($consultation->fresh([
            'clinic', 'doctor', 'patientProfile.user', 'appointment', 'clinicalHistoryRecord.entries.section', 'codings', 'signedBy',
        ])))->response()->setStatusCode(201);
    }

    public function index(Request $request): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);

        $query = Consultation::query()
            ->where('doctor_user_id', $doctor->id)
            ->with(['clinic', 'doctor', 'patientProfile.user', 'appointment', 'clinicalHistoryRecord.entries.section', 'codings', 'signedBy'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($clinic !== null) {
            $query->where('clinic_id', $clinic->id);
        } else {
            $query->whereNull('clinic_id');
        }

        $consultations = $query->get();

        return response()->json([
            'success' => true,
            'data' => ConsultationResource::collection($consultations),
        ]);
    }

    public function show(Request $request, Consultation $consultation): ConsultationResource
    {
        $doctor = $request->user();
        $doctor->loadMissing('doctorProfile');
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $this->assertConsultationAccessible($doctor, $clinic, $consultation);

        return new ConsultationResource($consultation);
    }

    public function update(
        DoctorConsultationUpdateRequest $request,
        Consultation $consultation,
        DoctorScheduling $scheduling,
    ): ConsultationResource {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $this->assertConsultationAccessible($doctor, $clinic, $consultation);

        $data = $request->validated();
        $consultation = DB::transaction(function () use ($data, $doctor, $clinic, $consultation, $scheduling, $request): Consultation {
            $lockedConsultation = Consultation::query()
                ->whereKey($consultation->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertConsultationAccessible($doctor, $clinic, $lockedConsultation);
            $lockedConsultation->loadMissing(['patientProfile', 'clinicalHistoryRecord.entries.section']);
            $beforeAudit = $this->auditSnapshot($lockedConsultation);

            if (in_array($lockedConsultation->status, [ConsultationStatus::Signed, ConsultationStatus::Canceled], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Una consulta firmada o cancelada no se puede modificar.'],
                ]);
            }

            // The encounter link is immutable, including consultations without an appointment.
            if (array_key_exists('appointment_id', $data)
                && ($data['appointment_id'] === null ? null : (int) $data['appointment_id']) !== $lockedConsultation->appointment_id) {
                throw ValidationException::withMessages([
                    'appointment_id' => ['No se puede cambiar la cita de una consulta registrada.'],
                ]);
            }
            $previousStatus = $lockedConsultation->status;
            $appointment = $lockedConsultation->appointment_id === null ? null
                : $this->lockAppointment($doctor, $clinic, $lockedConsultation->patientProfile, $lockedConsultation->appointment_id, $lockedConsultation->id);

            $objective = $lockedConsultation->objective;
            if (array_key_exists('objective', $data)) {
                $objective = $this->resolveObjective($data['objective'], $clinic, $lockedConsultation->patientProfile);
            }
            $nextStatus = ConsultationStatus::from($data['status'] ?? $lockedConsultation->status->value);
            $scheduling->assertConsultationStatusTransition($lockedConsultation->status, $nextStatus);

            $updates = [
                'status' => $nextStatus,
                'chief_complaint' => $data['chief_complaint'] ?? $lockedConsultation->chief_complaint,
                'objective' => $objective,
                'appointment_id' => $data['appointment_id'] ?? $lockedConsultation->appointment_id,
            ];

            if ($nextStatus === ConsultationStatus::Completed && $lockedConsultation->completed_at === null) {
                $updates['completed_at'] = now();
            }

            if ($nextStatus === ConsultationStatus::Signed) {
                $profile = $doctor->doctorProfile;
                $medicalLicense = trim((string) $profile?->medical_license);
                $licenseCountry = $profile?->medical_license_country_code ?? $doctor->country_code;
                $licenseIssuer = $profile?->medical_license_issuer;

                if ($medicalLicense === '') {
                    throw ValidationException::withMessages([
                        'status' => ['No puedes firmar una consulta sin cédula o licencia profesional registrada.'],
                    ]);
                }

                if ($licenseCountry === 'MX' && preg_match('/^\\d+$/', $medicalLicense) !== 1) {
                    throw ValidationException::withMessages([
                        'status' => ['La cédula profesional mexicana debe registrarse con su número oficial SEP/DGP antes de firmar.'],
                    ]);
                }

                $signedAt = now();
                $updates['signed_by_user_id'] = $doctor->id;
                $updates['signed_doctor_name'] = $doctor->name;
                $updates['signed_doctor_medical_license'] = $medicalLicense;
                $updates['signed_doctor_license_country_code'] = $licenseCountry;
                $updates['signed_doctor_license_issuer'] = $licenseIssuer;
                $updates['signed_doctor_specialty_license'] = $profile?->specialty_license;
                $updates['signed_doctor_specialty'] = $profile?->specialty;
                $updates['signed_at'] = $signedAt;
                $updates['completed_at'] ??= $lockedConsultation->completed_at ?? $signedAt;
            }

            $this->syncAppointmentStatus($appointment, $nextStatus);
            $lockedConsultation->fill($updates)->save();

            if ($lockedConsultation->clinicalHistoryRecord !== null) {
                $lockedConsultation->clinicalHistoryRecord->fill(['objective' => $objective])->save();
                if (array_key_exists('sections', $data)) {
                    $this->syncSections($lockedConsultation->clinicalHistoryRecord, $data['sections'] ?? []);
                }
            }

            if (array_key_exists('codings', $data)) {
                $this->syncCodings($lockedConsultation, $data['codings'] ?? [], $doctor);
            }

            if ($nextStatus === ConsultationStatus::Signed) {
                if ($lockedConsultation->clinicalHistoryRecord !== null) {
                    $profile = $doctor->doctorProfile;
                    $this->syncSections($lockedConsultation->clinicalHistoryRecord, [
                        'clinician_identification' => [
                            'clinician_name' => $doctor->name,
                            'title' => 'Cédula profesional: '.$profile?->medical_license
                                .($profile?->specialty_license ? ' · Cédula especialidad: '.$profile->specialty_license : ''),
                            'unit' => $clinic?->name ?? 'Práctica independiente',
                        ],
                    ]);
                }

                $lockedConsultation->refresh()->loadMissing(['clinicalHistoryRecord.entries.section', 'codings']);
                $lockedConsultation->forceFill([
                    'signature_digest' => hash('sha256', json_encode([
                        'consultation_id' => $lockedConsultation->id,
                        'patient_profile_id' => $lockedConsultation->patient_profile_id,
                        'appointment_id' => $lockedConsultation->appointment_id,
                        'chief_complaint' => $lockedConsultation->chief_complaint,
                        'objective' => $lockedConsultation->objective,
                        'sections' => $lockedConsultation->clinicalHistoryRecord?->entries
                            ->sortBy(fn ($entry) => (string) $entry->section?->key)
                            ->mapWithKeys(fn ($entry): array => [(string) $entry->section?->key => $entry->values])
                            ->all(),
                        'codings' => $lockedConsultation->codings
                            ->sortBy(fn ($coding) => implode('|', [
                                $coding->category->value,
                                $coding->system,
                                $coding->code,
                            ]))
                            ->map(fn ($coding): array => [
                                'category' => $coding->category->value,
                                'system' => $coding->system,
                                'code' => $coding->code,
                                'display' => $coding->display,
                                'status' => $coding->status,
                            ])
                            ->values()
                            ->all(),
                        'signed_doctor_name' => $lockedConsultation->signed_doctor_name,
                        'signed_doctor_medical_license' => $lockedConsultation->signed_doctor_medical_license,
                        'signed_doctor_license_country_code' => $lockedConsultation->signed_doctor_license_country_code,
                        'signed_doctor_license_issuer' => $lockedConsultation->signed_doctor_license_issuer,
                        'signed_doctor_specialty_license' => $lockedConsultation->signed_doctor_specialty_license,
                        'signed_at' => $lockedConsultation->signed_at?->toIso8601String(),
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                ])->save();
            }

            $this->recordAudit($request, $clinic, $lockedConsultation, $previousStatus, $beforeAudit);

            return $lockedConsultation->fresh([
                'clinic', 'doctor', 'patientProfile.user', 'appointment', 'clinicalHistoryRecord.entries.section', 'codings', 'signedBy',
            ]);
        });

        return new ConsultationResource($consultation);
    }

    /**
     * @param  array<int, array<string, mixed>>  $codings
     */
    private function syncCodings(Consultation $consultation, array $codings, User $doctor): void
    {
        $consultation->codings()->delete();

        $unique = [];
        foreach ($codings as $coding) {
            $key = implode('|', [$coding['category'], $coding['system'], $coding['code']]);
            $unique[$key] = $coding;
        }

        foreach ($unique as $coding) {
            $consultation->codings()->create([
                'patient_profile_id' => $consultation->patient_profile_id,
                'recorded_by_user_id' => $doctor->id,
                'category' => $coding['category'],
                'system' => $coding['system'],
                'code' => $coding['code'],
                'display' => $coding['display'],
                'status' => $coding['status'] ?? null,
                'recorded_at' => now(),
            ]);
        }

        $consultation->unsetRelation('codings');
    }

    private function syncSections(ClinicalHistoryRecord $record, array $sections): void
    {
        if ($sections === []) {
            return;
        }

        $sectionIds = ClinicalHistorySection::query()
            ->whereIn('key', array_keys($sections))
            ->pluck('id', 'key');

        foreach ($sections as $key => $values) {
            $sectionId = $sectionIds[$key] ?? null;
            if ($sectionId === null) {
                continue;
            }

            $entry = $record->entries()->firstOrNew(['clinical_history_section_id' => $sectionId]);
            $entry->values = array_replace($entry->values ?? [], $values);
            $entry->save();
        }
    }

    private function resolveObjective(?string $objective, ?Clinic $clinic, PatientProfile $patient): ?string
    {
        if (is_string($objective) && trim($objective) !== '') {
            return $objective;
        }

        if ($clinic === null) {
            return null;
        }

        $intake = PatientIntake::query()
            ->where('clinic_id', $clinic->id)
            ->where('patient_profile_id', $patient->id)
            ->latest('id')
            ->first();

        return $intake?->objective;
    }

    private function assertPatientConnected(User $doctor, ?Clinic $clinic, PatientProfile $patient): void
    {
        if ($clinic === null) {
            if ($patient->clinic_id !== null || $patient->owner_doctor_user_id !== $doctor->id) {
                abort(404, 'Paciente no encontrado.');
            }

            return;
        }

        if ($patient->clinic_id !== $clinic->id) {
            abort(404, 'Paciente no encontrado en la clínica del médico.');
        }

        $connected = Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('patient_profile_id', $patient->id)
            ->where('doctor_user_id', $doctor->id)
            ->exists()
            || PatientIntake::query()
                ->where('clinic_id', $clinic->id)
                ->where('patient_profile_id', $patient->id)
                ->where('requested_doctor_user_id', $doctor->id)
                ->exists();

        if ($connected === false) {
            abort(403, 'No tienes un vínculo clínico con este paciente.');
        }
    }

    private function lockAppointment(User $doctor, ?Clinic $clinic, PatientProfile $patient, int $appointmentId, ?int $consultationId = null): Appointment
    {
        $query = Appointment::query()
            ->whereKey($appointmentId)
            ->where('patient_profile_id', $patient->id)
            ->where('doctor_user_id', $doctor->id);

        if ($clinic !== null) {
            $query->where('clinic_id', $clinic->id);
        } else {
            $query->whereNull('clinic_id');
        }

        $appointment = $query->lockForUpdate()->first();

        if ($appointment === null) {
            abort(403, 'La cita no pertenece a este paciente en tu clínica.');
        }

        if (Consultation::query()->where('appointment_id', $appointmentId)
            ->when($consultationId !== null, fn ($query) => $query->where('id', '!=', $consultationId))->exists()) {
            throw ValidationException::withMessages([
                'appointment_id' => ['Esta cita ya tiene una consulta. Abre el registro existente.'],
            ]);
        }

        if (in_array($appointment->status, [AppointmentStatus::Canceled, AppointmentStatus::NoShow], true)
            || ($consultationId === null && $appointment->status === AppointmentStatus::Completed)) {
            throw ValidationException::withMessages([
                'appointment_id' => ['La cita está cerrada y no admite una nueva atención.'],
            ]);
        }

        return $appointment;
    }

    private function syncAppointmentStatus(?Appointment $appointment, ConsultationStatus $status): void
    {
        if ($appointment === null || $status === ConsultationStatus::Draft) {
            return;
        }

        $next = match ($status) {
            ConsultationStatus::Completed, ConsultationStatus::Signed => AppointmentStatus::Completed,
            ConsultationStatus::Canceled => AppointmentStatus::Canceled,
            default => AppointmentStatus::InConsultation,
        };

        if ($appointment->status === AppointmentStatus::Completed && $next !== AppointmentStatus::Completed) {
            throw ValidationException::withMessages(['appointment_id' => ['La cita ya está completada.']]);
        }

        $appointment->fill(['status' => $next])->save();
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function recordAudit(
        Request $request,
        ?Clinic $clinic,
        Consultation $consultation,
        ?ConsultationStatus $previous,
        ?array $before = null,
    ): void {
        $consultation->loadMissing(['clinicalHistoryRecord.entries.section', 'codings']);

        $action = match (true) {
            $previous === null => 'consultation.created',
            $consultation->status === ConsultationStatus::Signed => 'consultation.signed',
            $consultation->status === ConsultationStatus::Canceled => 'consultation.canceled',
            default => 'consultation.updated',
        };

        app(RecordAdminAuditEvent::class)->handle(
            actor: $request->user(),
            request: $request,
            module: 'clinical',
            action: $action,
            resourceType: 'consultation',
            resourceId: $consultation->id,
            clinic: $clinic,
            metadata: ['previous_status' => $previous?->value, 'status' => $consultation->status->value],
        );

        app(RecordClinicalAuditEvent::class)->handle(
            actor: $request->user(),
            request: $request,
            consultation: $consultation,
            action: $action,
            before: $before,
            after: $this->auditSnapshot($consultation),
        );
    }

    /**
     * @return  array<string, mixed>
     */
    private function auditSnapshot(Consultation $consultation): array
    {
        $consultation->loadMissing(['clinicalHistoryRecord.entries.section', 'codings']);

        return [
            'consultation_id' => $consultation->id,
            'patient_profile_id' => $consultation->patient_profile_id,
            'appointment_id' => $consultation->appointment_id,
            'status' => $consultation->status->value,
            'chief_complaint' => $consultation->chief_complaint,
            'objective' => $consultation->objective,
            'sections' => $consultation->clinicalHistoryRecord?->entries
                ->sortBy(fn ($entry) => (string) $entry->section?->key)
                ->mapWithKeys(fn ($entry): array => [(string) $entry->section?->key => $entry->values])
                ->all() ?? [],
            'codings' => $consultation->codings
                ->sortBy(fn ($coding) => implode('|', [
                    $coding->category->value,
                    $coding->system,
                    $coding->code,
                ]))
                ->map(fn ($coding): array => [
                    'category' => $coding->category->value,
                    'system' => $coding->system,
                    'code' => $coding->code,
                    'display' => $coding->display,
                    'status' => $coding->status,
                ])
                ->values()
                ->all(),
            'signed_by_user_id' => $consultation->signed_by_user_id,
            'signed_at' => $consultation->signed_at?->toIso8601String(),
            'signature_digest' => $consultation->signature_digest,
            'completed_at' => $consultation->completed_at?->toIso8601String(),
        ];
    }

    private function assertConsultationAccessible(User $doctor, ?Clinic $clinic, Consultation $consultation): void
    {
        $sameClinic = $clinic === null
            ? $consultation->clinic_id === null
            : $consultation->clinic_id === $clinic->id;

        if ($sameClinic === false || $consultation->doctor_user_id !== $doctor->id) {
            abort(404, 'Consulta no encontrada.');
        }
    }
}
