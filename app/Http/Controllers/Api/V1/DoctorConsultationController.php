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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DoctorConsultationController extends Controller
{
    public function store(DoctorConsultationStoreRequest $request, PatientProfile $patient): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::workingClinic($doctor);
        $this->assertPatientConnected($doctor, $clinic, $patient);

        $data = $request->validated();

        if (isset($data['appointment_id'])) {
            $this->assertAppointmentOwned($doctor, $clinic, $patient, (int) $data['appointment_id']);
        }

        $objective = $this->resolveObjective($data['objective'] ?? null, $clinic, $patient);

        $consultation = DB::transaction(function () use ($doctor, $clinic, $patient, $data, $objective): Consultation {
            $record = ClinicalHistoryRecord::query()->create([
                'clinic_id' => $clinic->id,
                'patient_profile_id' => $patient->id,
                'doctor_user_id' => $doctor->id,
                'appointment_id' => $data['appointment_id'] ?? null,
                'created_by_user_id' => $doctor->id,
                'source' => 'consultation',
                'objective' => $objective,
            ]);

            $this->syncSections($record, $data['sections'] ?? []);

            $signedBy = $data['signature']['signed_by'] ?? null;
            $signedAt = $data['signature']['signed_at'] ?? null;

            return Consultation::query()->create([
                'clinic_id' => $clinic->id,
                'doctor_user_id' => $doctor->id,
                'patient_profile_id' => $patient->id,
                'appointment_id' => $data['appointment_id'] ?? null,
                'clinical_history_record_id' => $record->id,
                'created_by_user_id' => $doctor->id,
                'status' => $data['status'] ?? ConsultationStatus::InProgress->value,
                'chief_complaint' => $data['chief_complaint'],
                'objective' => $objective,
                'signed_by_user_id' => $signedBy,
                'signed_at' => $signedAt,
            ]);
        });

        return (new ConsultationResource($consultation->fresh([
            'clinic', 'doctor', 'patientProfile.user', 'appointment', 'clinicalHistoryRecord.entries.section', 'signedBy',
        ])))->response()->setStatusCode(201);
    }

    public function index(Request $request): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::workingClinic($doctor);

        $consultations = Consultation::query()
            ->where('clinic_id', $clinic->id)
            ->where('doctor_user_id', $doctor->id)
            ->with(['clinic', 'doctor', 'patientProfile.user', 'appointment', 'clinicalHistoryRecord.entries.section', 'signedBy'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => ConsultationResource::collection($consultations),
        ]);
    }

    public function show(Request $request, Consultation $consultation): ConsultationResource
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::workingClinic($doctor);
        $this->assertConsultationAccessible($doctor, $clinic, $consultation);

        return new ConsultationResource($consultation);
    }

    public function update(DoctorConsultationUpdateRequest $request, Consultation $consultation): ConsultationResource
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::workingClinic($doctor);
        $this->assertConsultationAccessible($doctor, $clinic, $consultation);

        $data = $request->validated();

        if (isset($data['appointment_id'])) {
            $this->assertAppointmentOwned($doctor, $clinic, $consultation->patientProfile, (int) $data['appointment_id']);
        }

        $objective = $consultation->objective;
        if (array_key_exists('objective', $data)) {
            $objective = $this->resolveObjective($data['objective'], $clinic, $consultation->patientProfile);
        }

        DB::transaction(function () use ($data, $objective, $consultation): void {
            $consultation->fill([
                'status' => $data['status'] ?? $consultation->status->value,
                'chief_complaint' => $data['chief_complaint'] ?? $consultation->chief_complaint,
                'objective' => $objective,
                'appointment_id' => $data['appointment_id'] ?? $consultation->appointment_id,
                'signed_by_user_id' => $data['signature']['signed_by'] ?? $consultation->signed_by_user_id,
                'signed_at' => $data['signature']['signed_at'] ?? $consultation->signed_at,
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

    private function resolveObjective(?string $objective, Clinic $clinic, PatientProfile $patient): ?string
    {
        if (is_string($objective) && trim($objective) !== '') {
            return $objective;
        }

        $intake = PatientIntake::query()
            ->where('clinic_id', $clinic->id)
            ->where('patient_profile_id', $patient->id)
            ->latest('id')
            ->first();

        return $intake?->objective;
    }

    private function assertPatientConnected(User $doctor, Clinic $clinic, PatientProfile $patient): void
    {
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

    private function assertAppointmentOwned(User $doctor, Clinic $clinic, PatientProfile $patient, int $appointmentId): void
    {
        $owned = Appointment::query()
            ->whereKey($appointmentId)
            ->where('clinic_id', $clinic->id)
            ->where('patient_profile_id', $patient->id)
            ->where('doctor_user_id', $doctor->id)
            ->exists();

        if (! $owned) {
            abort(403, 'La cita no pertenece a este paciente en tu clínica.');
        }
    }

    private function assertConsultationAccessible(User $doctor, Clinic $clinic, Consultation $consultation): void
    {
        if ($consultation->clinic_id !== $clinic->id || $consultation->doctor_user_id !== $doctor->id) {
            abort(404, 'Consulta no encontrada.');
        }
    }
}
