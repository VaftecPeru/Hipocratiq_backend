<?php

namespace App\Http\Controllers\Api\V1;

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
    ): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $this->assertPatientConnected($doctor, $clinic, $patient);

        $data = $request->validated();

        if (isset($data['appointment_id'])) {
            $this->assertAppointmentOwned($doctor, $clinic, $patient, (int) $data['appointment_id']);
        }

        $objective = $this->resolveObjective($data['objective'] ?? null, $clinic, $patient);
        $status = ConsultationStatus::from($data['status'] ?? ConsultationStatus::InProgress->value);
        $scheduling->assertConsultationStatusTransition(ConsultationStatus::Draft, $status);

        $consultation = DB::transaction(function () use ($doctor, $clinic, $patient, $data, $objective, $status): Consultation {
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

            return Consultation::query()->create([
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
        });

        return (new ConsultationResource($consultation->fresh([
            'clinic', 'doctor', 'patientProfile.user', 'appointment', 'clinicalHistoryRecord.entries.section', 'signedBy',
        ])))->response()->setStatusCode(201);
    }

    public function index(Request $request): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);

        $query = Consultation::query()
            ->where('doctor_user_id', $doctor->id)
            ->with(['clinic', 'doctor', 'patientProfile.user', 'appointment', 'clinicalHistoryRecord.entries.section', 'signedBy'])
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
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $this->assertConsultationAccessible($doctor, $clinic, $consultation);

        return new ConsultationResource($consultation);
    }

    public function update(
        DoctorConsultationUpdateRequest $request,
        Consultation $consultation,
        DoctorScheduling $scheduling,
    ): ConsultationResource
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $this->assertConsultationAccessible($doctor, $clinic, $consultation);

        $data = $request->validated();

        if ($consultation->status === ConsultationStatus::Signed) {
            throw ValidationException::withMessages([
                'status' => ['Una consulta firmada no se puede modificar.'],
            ]);
        }

        if (isset($data['appointment_id'])) {
            $this->assertAppointmentOwned($doctor, $clinic, $consultation->patientProfile, (int) $data['appointment_id']);
        }

        $objective = $consultation->objective;
        if (array_key_exists('objective', $data)) {
            $objective = $this->resolveObjective($data['objective'], $clinic, $consultation->patientProfile);
        }
        $nextStatus = ConsultationStatus::from($data['status'] ?? $consultation->status->value);
        $scheduling->assertConsultationStatusTransition($consultation->status, $nextStatus);

        DB::transaction(function () use ($data, $doctor, $nextStatus, $objective, $consultation): void {
            $updates = [
                'status' => $nextStatus,
                'chief_complaint' => $data['chief_complaint'] ?? $consultation->chief_complaint,
                'objective' => $objective,
                'appointment_id' => $data['appointment_id'] ?? $consultation->appointment_id,
            ];

            if ($nextStatus === ConsultationStatus::Completed && $consultation->completed_at === null) {
                $updates['completed_at'] = now();
            }

            if ($nextStatus === ConsultationStatus::Signed) {
                $updates['signed_by_user_id'] = $doctor->id;
                $updates['signed_at'] = now();
                $updates['completed_at'] ??= $consultation->completed_at ?? now();
            }

            $consultation->fill([
                ...$updates,
            ])->save();

            if ($consultation->clinicalHistoryRecord !== null) {
                $consultation->clinicalHistoryRecord->fill(['objective' => $objective])->save();
                if (array_key_exists('sections', $data)) {
                    $this->syncSections($consultation->clinicalHistoryRecord, $data['sections'], true);
                }
            }
        });

        return new ConsultationResource($consultation->fresh([
            'clinic', 'doctor', 'patientProfile.user', 'appointment', 'clinicalHistoryRecord.entries.section', 'signedBy',
        ]));
    }

    private function syncSections(ClinicalHistoryRecord $record, array $sections, bool $replace = false): void
    {
        if ($sections === []) {
            return;
        }

        $sectionIds = ClinicalHistorySection::query()
            ->whereIn('key', array_keys($sections))
            ->pluck('id', 'key');

        if ($replace) {
            $record->entries()
                ->whereNotIn('clinical_history_section_id', $sectionIds->values())
                ->delete();
        }

        foreach ($sections as $key => $values) {
            $sectionId = $sectionIds[$key] ?? null;
            if ($sectionId === null) {
                continue;
            }

            $record->entries()->updateOrCreate(
                ['clinical_history_section_id' => $sectionId],
                ['values' => is_array($values) ? $values : ['value' => $values]],
            );
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

        if (! $connected) {
            abort(403, 'No tienes un vínculo clínico con este paciente.');
        }
    }

    private function assertAppointmentOwned(User $doctor, ?Clinic $clinic, PatientProfile $patient, int $appointmentId): void
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

        $owned = $query->exists();

        if (! $owned) {
            abort(403, 'La cita no pertenece a este paciente en tu clínica.');
        }
    }

    private function assertConsultationAccessible(User $doctor, ?Clinic $clinic, Consultation $consultation): void
    {
        $sameClinic = $clinic === null
            ? $consultation->clinic_id === null
            : $consultation->clinic_id === $clinic->id;

        if (! $sameClinic || $consultation->doctor_user_id !== $doctor->id) {
            abort(404, 'Consulta no encontrada.');
        }
    }
}
