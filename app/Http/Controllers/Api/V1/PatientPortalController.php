<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Clinic\UpdateClinicPatient;
use App\Enums\ConsultationStatus;
use App\Enums\LaboratoryOrderStatus;
use App\Enums\PrescriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PatientIntakeDraftStoreRequest;
use App\Http\Requests\Api\V1\PatientProfileUpdateRequest;
use App\Http\Requests\Api\V1\PatientTriageStoreRequest;
use App\Http\Resources\Api\V1\PatientAppointmentResource;
use App\Http\Resources\Api\V1\PatientConsultationResource;
use App\Http\Resources\Api\V1\PatientIntakeDraftResource;
use App\Http\Resources\Api\V1\PatientLaboratoryResultResource;
use App\Http\Resources\Api\V1\PatientPrescriptionResource;
use App\Http\Resources\Api\V1\PatientProfileResource;
use App\Http\Resources\Api\V1\PatientTriageResource;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\DoctorPrescription;
use App\Models\LaboratoryOrder;
use App\Models\PatientIntake;
use App\Models\PatientIntakeDraft;
use App\Models\PatientProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PatientPortalController extends Controller
{
    private const PATIENT_SECTION_FIELDS = [
        'patient_identification' => [
            'name', 'age', 'document_type', 'document_number', 'birth_date', 'gender', 'occupation',
            'address', 'guardian_name', 'relationship', 'phone_number', 'email', 'emergency_contact', 'insurance',
        ],
        'family_history' => ['description'],
        'non_pathological_history' => ['description'],
        'pathological_history' => ['description'],
        'gyneco_obstetric_history' => ['description'],
        'present_illness' => ['reason', 'illness_narrative'],
        'systems_review' => ['description'],
        'vital_signs' => [
            'blood_pressure_systolic', 'blood_pressure_diastolic', 'temperature_c', 'heart_rate',
            'respiratory_rate', 'oxygen_saturation', 'weight_kg', 'height_cm', 'notes',
        ],
    ];

    public function profile(Request $request): PatientProfileResource
    {
        return new PatientProfileResource($this->patient($request));
    }

    public function updateProfile(
        PatientProfileUpdateRequest $request,
        UpdateClinicPatient $updatePatient,
    ): PatientProfileResource {
        $patient = $updatePatient->handle($request->validated(), $this->patient($request));

        return new PatientProfileResource($patient);
    }

    public function appointments(Request $request): JsonResponse
    {
        $patient = $this->patient($request);
        $appointments = $this->patientQuery(Appointment::query(), $patient)
            ->with(['clinic', 'doctor.doctorProfile'])
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => PatientAppointmentResource::collection($appointments),
            'meta' => [
                'total' => $appointments->count(),
                'upcoming' => $appointments->filter(fn (Appointment $appointment): bool => $appointment->scheduled_at?->isFuture() && ! in_array($appointment->status->value, ['canceled', 'no_show'], true))->count(),
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $patient = $this->patient($request);
        $consultations = $this->patientQuery(Consultation::query(), $patient)
            ->whereIn('status', [ConsultationStatus::Completed, ConsultationStatus::Signed])
            ->with(['clinic', 'doctor.doctorProfile', 'appointment', 'clinicalHistoryRecord.entries.section'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => PatientConsultationResource::collection($consultations),
            'meta' => ['total' => $consultations->count()],
        ]);
    }

    public function prescriptions(Request $request): JsonResponse
    {
        $patient = $this->patient($request);
        $prescriptions = $this->patientQuery(DoctorPrescription::query(), $patient)
            ->with(['clinic', 'doctor.doctorProfile', 'consultation'])
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => PatientPrescriptionResource::collection($prescriptions),
            'meta' => [
                'total' => $prescriptions->count(),
                'active' => $prescriptions->where('status', PrescriptionStatus::Active)->count(),
                'renewal_due' => $prescriptions->where('status', PrescriptionStatus::RenewalDue)->count(),
            ],
        ]);
    }

    public function results(Request $request): JsonResponse
    {
        $patient = $this->patient($request);
        $results = $this->patientQuery(LaboratoryOrder::query(), $patient)
            ->with(['clinic', 'doctor.doctorProfile', 'consultation'])
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => PatientLaboratoryResultResource::collection($results),
            'meta' => [
                'total' => $results->count(),
                'available' => $results->whereIn('status', [LaboratoryOrderStatus::Ready, LaboratoryOrderStatus::Reviewed])->count(),
            ],
        ]);
    }

    public function triage(Request $request): JsonResponse
    {
        $patient = $this->patient($request);
        $triage = $this->patientQuery(PatientIntake::query(), $patient)
            ->with(['clinic', 'registrar'])
            ->latest('id')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $triage === null ? null : new PatientTriageResource($triage),
            'meta' => ['available' => $triage !== null],
        ]);
    }

    public function intakeDraft(Request $request): PatientIntakeDraftResource
    {
        $patient = $this->patient($request);
        $draft = $this->intakeDraftQuery($patient)->latest('id')->first();

        return new PatientIntakeDraftResource($this->draftForResponse($patient, $draft));
    }

    public function saveIntakeDraft(PatientIntakeDraftStoreRequest $request): PatientIntakeDraftResource
    {
        $patient = $this->patient($request);
        $data = $request->validated();

        $draft = DB::transaction(function () use ($data, $patient): PatientIntakeDraft {
            $draft = $this->intakeDraftQuery($patient)->lockForUpdate()->latest('id')->first();

            if ($draft === null) {
                $draft = new PatientIntakeDraft([
                    'patient_profile_id' => $patient->id,
                    'clinic_id' => $patient->clinic_id,
                ]);
            }

            $draft->sections = $this->normalizeDraftSections($patient, $data['sections']);
            $draft->declaration_accepted_at ??= now();
            $draft->save();

            return $draft->fresh('clinic') ?? $draft;
        });

        return new PatientIntakeDraftResource($this->draftForResponse($patient, $draft));
    }

    public function storeTriage(PatientTriageStoreRequest $request): JsonResponse
    {
        $patient = $this->patient($request);

        if ($patient->clinic_id === null) {
            return response()->json([
                'success' => false,
                'code' => 'PATIENT_CLINIC_REQUIRED',
                'message' => 'El triaje no está disponible porque tu perfil no tiene una clínica vinculada.',
            ], 422);
        }

        $data = $request->validated();
        $triage = DB::transaction(fn (): PatientIntake => PatientIntake::query()->create([
            'clinic_id' => $patient->clinic_id,
            'patient_profile_id' => $patient->id,
            'registered_by_user_id' => $request->user()->id,
            'objective' => trim($data['reason']),
            'temperature_c' => $data['temperature_c'] ?? null,
            'systolic_bp' => $data['systolic_bp'] ?? null,
            'diastolic_bp' => $data['diastolic_bp'] ?? null,
            'heart_rate' => $data['heart_rate'] ?? null,
            'respiratory_rate' => $data['respiratory_rate'] ?? null,
            'oxygen_saturation' => $data['oxygen_saturation'] ?? null,
            'triage_priority' => $data['triage_priority'] ?? null,
            'triage_notes' => $data['triage_notes'] ?? null,
        ]));

        return response()->json([
            'success' => true,
            'data' => new PatientTriageResource($triage->load(['clinic', 'registrar'])),
        ], 201);
    }

    public function membership(Request $request): JsonResponse
    {
        $this->patient($request);

        return response()->json([
            'success' => true,
            'data' => null,
            'meta' => [
                'available' => false,
                'message' => 'No existe una membresía individual persistida para este paciente.',
            ],
        ]);
    }

    public function epidemiology(Request $request): JsonResponse
    {
        $this->patient($request);

        return response()->json([
            'success' => true,
            'data' => null,
            'meta' => [
                'available' => false,
                'message' => 'No hay una fuente epidemiológica vinculada a este paciente.',
            ],
        ]);
    }

    private function patient(Request $request): PatientProfile
    {
        $patient = PatientProfile::query()
            ->where('user_id', $request->user()->id)
            ->with(['user', 'clinic'])
            ->first();

        if ($patient === null) {
            abort(404, 'No encontramos un perfil de paciente para esta cuenta.');
        }

        return $patient;
    }

    private function intakeDraftQuery(PatientProfile $patient): Builder
    {
        return PatientIntakeDraft::query()
            ->where('patient_profile_id', $patient->id)
            ->when(
                $patient->clinic_id === null,
                fn (Builder $query): Builder => $query->whereNull('clinic_id'),
                fn (Builder $query): Builder => $query->where('clinic_id', $patient->clinic_id),
            );
    }

    private function draftForResponse(PatientProfile $patient, ?PatientIntakeDraft $draft): PatientIntakeDraft
    {
        $draft ??= new PatientIntakeDraft([
            'patient_profile_id' => $patient->id,
            'clinic_id' => $patient->clinic_id,
        ]);
        $draft->sections = $this->normalizeDraftSections($patient, $draft->sections ?? []);
        $draft->setRelation('clinic', $patient->clinic);

        return $draft;
    }

    /** @param array<string, mixed> $sections */
    private function normalizeDraftSections(PatientProfile $patient, array $sections): array
    {
        $defaults = [
            'patient_identification' => [
                'name' => $patient->user?->name,
                'age' => $patient->date_of_birth?->age,
                'document_type' => $patient->document_type,
                'document_number' => $patient->document_number,
                'birth_date' => $patient->date_of_birth?->format('Y-m-d'),
                'gender' => $patient->sex,
                'occupation' => null,
                'address' => $patient->address,
                'guardian_name' => null,
                'relationship' => null,
                'phone_number' => $patient->user?->phone_number,
                'email' => $patient->user?->email,
                'emergency_contact' => $patient->emergency_contact,
                'insurance' => $patient->insurance,
            ],
            'family_history' => ['description' => null],
            'non_pathological_history' => ['description' => null],
            'pathological_history' => ['description' => null],
            'gyneco_obstetric_history' => ['description' => null],
            'present_illness' => ['reason' => null, 'illness_narrative' => null],
            'systems_review' => ['description' => null],
            'vital_signs' => [
                'blood_pressure_systolic' => null,
                'blood_pressure_diastolic' => null,
                'temperature_c' => null,
                'heart_rate' => null,
                'respiratory_rate' => null,
                'oxygen_saturation' => null,
                'weight_kg' => null,
                'height_cm' => null,
                'notes' => null,
            ],
        ];

        $safeSections = [];
        foreach (self::PATIENT_SECTION_FIELDS as $section => $fields) {
            $sectionValues = is_array($sections[$section] ?? null) ? $sections[$section] : [];
            $safeSections[$section] = array_replace(
                $defaults[$section],
                array_intersect_key($sectionValues, array_flip($fields)),
            );
        }

        return $safeSections;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function patientQuery(Builder $query, PatientProfile $patient): Builder
    {
        $query->where('patient_profile_id', $patient->id);

        return $patient->clinic_id === null
            ? $query->whereNull('clinic_id')
            : $query->where('clinic_id', $patient->clinic_id);
    }
}
