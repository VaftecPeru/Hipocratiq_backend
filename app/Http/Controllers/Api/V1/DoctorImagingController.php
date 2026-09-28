<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Enums\ImagingStudyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ImagingStudyStatusUpdateRequest;
use App\Http\Requests\Api\V1\ImagingStudyStoreRequest;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Consultation;
use App\Models\ImagingStudy;
use App\Models\PatientProfile;
use App\Models\User;
use App\Support\Clinical\DoctorClinicScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DoctorImagingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_column(ImagingStudyStatus::cases(), 'value'))],
        ]);
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);

        $studies = $this->doctorQuery(ImagingStudy::query(), $doctor, $clinic)
            ->with(['clinic', 'patientProfile.user', 'consultation'])
            ->when(isset($validated['status']), fn (Builder $query): Builder => $query->where('status', $validated['status']))
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $studies->map(fn (ImagingStudy $study): array => $this->serialize($study))->values(),
            'meta' => [
                'total' => $studies->count(),
                'ordered' => $studies->where('status', ImagingStudyStatus::Ordered)->count(),
                'scheduled' => $studies->where('status', ImagingStudyStatus::Scheduled)->count(),
                'available' => $studies->where('status', ImagingStudyStatus::Available)->count(),
                'reviewed' => $studies->where('status', ImagingStudyStatus::Reviewed)->count(),
                'connection' => $this->connection(),
            ],
        ]);
    }

    public function store(ImagingStudyStoreRequest $request): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $data = $request->validated();
        $patient = PatientProfile::query()->findOrFail($data['patient_profile_id']);
        $this->assertPatientAccessible($doctor, $clinic, $patient);

        $consultation = isset($data['consultation_id'])
            ? $this->resolveConsultation($doctor, $clinic, (int) $data['consultation_id'], $patient->id)
            : null;

        $study = ImagingStudy::query()->create([
            'clinic_id' => $clinic?->id,
            'doctor_user_id' => $doctor->id,
            'patient_profile_id' => $patient->id,
            'consultation_id' => $consultation?->id,
            'accession_number' => $this->accessionNumber(),
            'modality' => strtoupper($data['modality']),
            'study_description' => trim($data['study_description']),
            'status' => ImagingStudyStatus::Ordered,
            'ordered_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);

        $this->audit($request, $clinic, $study, 'imaging.study.ordered');

        return response()->json([
            'success' => true,
            'data' => $this->serialize($study->fresh(['clinic', 'patientProfile.user', 'consultation'])),
        ], 201);
    }

    public function updateStatus(
        ImagingStudyStatusUpdateRequest $request,
        ImagingStudy $study,
    ): JsonResponse {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);

        $study = DB::transaction(function () use ($doctor, $clinic, $study, $request): ImagingStudy {
            $locked = $this->doctorQuery(ImagingStudy::query(), $doctor, $clinic)
                ->whereKey($study->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                abort(404, 'Estudio de imagen no encontrado.');
            }

            $data = $request->validated();
            $next = ImagingStudyStatus::from($data['status']);
            $this->assertTransition($locked->status, $next);

            $updates = ['status' => $next];
            foreach (['report_summary', 'study_at'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $data[$field];
                }
            }
            if ($next === ImagingStudyStatus::Available && $locked->available_at === null) {
                $updates['available_at'] = now();
            }
            if ($next === ImagingStudyStatus::Reviewed && $locked->reviewed_at === null) {
                $updates['reviewed_at'] = now();
            }

            $locked->update($updates);

            return $locked->fresh(['clinic', 'patientProfile.user', 'consultation']);
        });

        $this->audit($request, $clinic, $study, 'imaging.study.status_changed');

        return response()->json(['success' => true, 'data' => $this->serialize($study)]);
    }

    private function doctorQuery(Builder $query, User $doctor, ?Clinic $clinic): Builder
    {
        $query->where('doctor_user_id', $doctor->id);

        return $clinic === null
            ? $query->whereNull('clinic_id')
            : $query->where('clinic_id', $clinic->id);
    }

    private function assertPatientAccessible(User $doctor, ?Clinic $clinic, PatientProfile $patient): void
    {
        if ($clinic === null) {
            abort_unless($patient->clinic_id === null && $patient->owner_doctor_user_id === $doctor->id, 404);
            return;
        }

        abort_unless($patient->clinic_id === $clinic->id, 404);

        $connected = Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('patient_profile_id', $patient->id)
            ->where('doctor_user_id', $doctor->id)
            ->exists()
            || Consultation::query()
                ->where('clinic_id', $clinic->id)
                ->where('patient_profile_id', $patient->id)
                ->where('doctor_user_id', $doctor->id)
                ->exists();

        abort_unless($connected, 403, 'No tienes vínculo clínico con este paciente.');
    }

    private function resolveConsultation(User $doctor, ?Clinic $clinic, int $id, int $patientId): Consultation
    {
        $query = Consultation::query()
            ->whereKey($id)
            ->where('doctor_user_id', $doctor->id)
            ->where('patient_profile_id', $patientId);

        $clinic === null ? $query->whereNull('clinic_id') : $query->where('clinic_id', $clinic->id);

        return $query->firstOrFail();
    }

    private function assertTransition(ImagingStudyStatus $current, ImagingStudyStatus $next): void
    {
        if ($current === $next) {
            return;
        }

        $allowed = match ($current) {
            ImagingStudyStatus::Ordered => [ImagingStudyStatus::Scheduled, ImagingStudyStatus::Canceled],
            ImagingStudyStatus::Scheduled => [ImagingStudyStatus::Available, ImagingStudyStatus::Canceled],
            ImagingStudyStatus::Available => [ImagingStudyStatus::Reviewed],
            ImagingStudyStatus::Reviewed, ImagingStudyStatus::Canceled => [],
        };

        if (! in_array($next, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ['La transición de estado de imagenología no es válida.'],
            ]);
        }
    }

    private function accessionNumber(): string
    {
        do {
            $value = 'HIMG-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (ImagingStudy::query()->where('accession_number', $value)->exists());

        return $value;
    }

    private function connection(): array
    {
        $provider = (string) config('integrations.imaging.provider', 'unavailable');
        $configured = $provider !== '' && $provider !== 'unavailable';

        return [
            'status' => $configured ? 'configured' : 'not_configured',
            'provider' => $provider,
            'dicomweb' => filled(config('integrations.imaging.dicomweb_base_url')),
            'viewer' => filled(config('integrations.imaging.viewer_base_url')),
        ];
    }

    private function serialize(ImagingStudy $study): array
    {
        return [
            'id' => $study->id,
            'accession_number' => $study->accession_number,
            'study_instance_uid' => $study->study_instance_uid,
            'clinic' => $study->clinic ? ['id' => $study->clinic->id, 'name' => $study->clinic->name] : null,
            'patient' => [
                'id' => $study->patient_profile_id,
                'patient_code' => $study->patientProfile?->patient_code,
                'name' => $study->patientProfile?->user?->name,
            ],
            'consultation_id' => $study->consultation_id,
            'modality' => $study->modality,
            'study_description' => $study->study_description,
            'status' => $study->status->value,
            'status_label' => $study->status->label(),
            'report_summary' => $study->report_summary,
            'notes' => $study->notes,
            'ordered_at' => $study->ordered_at?->toIso8601String(),
            'study_at' => $study->study_at?->toIso8601String(),
            'available_at' => $study->available_at?->toIso8601String(),
            'reviewed_at' => $study->reviewed_at?->toIso8601String(),
            'pacs' => [
                'linked' => filled($study->study_instance_uid) && filled($study->pacs_study_key),
                'provider' => $study->pacs_provider,
            ],
        ];
    }

    private function audit(Request $request, ?Clinic $clinic, ImagingStudy $study, string $action): void
    {
        app(RecordAdminAuditEvent::class)->handle(
            actor: $request->user(),
            request: $request,
            module: 'clinical-imaging',
            action: $action,
            resourceType: 'imaging_study',
            resourceId: $study->id,
            resourceLabel: $study->accession_number,
            clinic: $clinic,
            metadata: [
                'patient_profile_id' => $study->patient_profile_id,
                'consultation_id' => $study->consultation_id,
                'modality' => $study->modality,
                'status' => $study->status->value,
            ],
        );
    }
}
