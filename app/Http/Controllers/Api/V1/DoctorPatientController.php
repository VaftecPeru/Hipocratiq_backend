<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Clinic\CreateClinicPatient;
use App\Actions\Clinic\UpdateClinicPatient;
use App\Actions\Doctor\CreateIndependentDoctorPatient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DoctorPatientStoreRequest;
use App\Http\Requests\Api\V1\DoctorPatientUpdateRequest;
use App\Http\Resources\Api\V1\ClinicalHistoryRecordResource;
use App\Http\Resources\Api\V1\DoctorPatientResource;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\PatientIntake;
use App\Models\PatientProfile;
use App\Models\User;
use App\Support\Clinical\DoctorClinicScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DoctorPatientController extends Controller
{
    public function patients(Request $request): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $query = PatientProfile::query()
            ->with(['user', 'clinic'])
            ->orderBy('id');

        if ($clinic !== null) {
            $patientIds = $this->connectedPatientIds($doctor, $clinic);
            if ($patientIds === []) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $query->where('clinic_id', $clinic->id)->whereIn('id', $patientIds);
        } else {
            $query->whereNull('clinic_id')->where('owner_doctor_user_id', $doctor->id);
        }

        $patients = $query->get();

        return response()->json([
            'success' => true,
            'data' => DoctorPatientResource::collection($patients),
        ]);
    }

    public function store(
        DoctorPatientStoreRequest $request,
        CreateIndependentDoctorPatient $createIndependentPatient,
        CreateClinicPatient $createClinicPatient,
    ): JsonResponse {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $data = $request->validated();

        $patient = DB::transaction(function () use ($doctor, $clinic, $data, $createIndependentPatient, $createClinicPatient): PatientProfile {
            if ($clinic === null) {
                return $createIndependentPatient->handle($data, $doctor);
            }

            $patient = $createClinicPatient->handle($data, $clinic, $doctor);
            DB::table('doctor_patient_links')->updateOrInsert(
                [
                    'clinic_id' => $clinic->id,
                    'doctor_user_id' => $doctor->id,
                    'patient_profile_id' => $patient->id,
                ],
                [
                    'linked_by_user_id' => $doctor->id,
                    'source' => 'doctor_registration',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            return $patient;
        })->load(['user', 'clinic']);

        return (new DoctorPatientResource($patient))->response()->setStatusCode(201);
    }

    public function show(Request $request, PatientProfile $patient): DoctorPatientResource
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $this->assertPatientAccessible($doctor, $clinic, $patient);

        return new DoctorPatientResource($patient->load(['user', 'clinic']));
    }

    public function update(
        DoctorPatientUpdateRequest $request,
        PatientProfile $patient,
        UpdateClinicPatient $updatePatient,
    ): DoctorPatientResource {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $this->assertIndependentPatient($doctor, $clinic, $patient);

        $patient = $updatePatient->handle($request->validated(), $patient)->load(['user', 'clinic']);

        return new DoctorPatientResource($patient);
    }

    public function clinicalHistory(Request $request, PatientProfile $patient): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $this->assertPatientAccessible($doctor, $clinic, $patient);

        $recordsQuery = $patient->clinicalHistoryRecords()
            ->where('doctor_user_id', $doctor->id)
            ->with(['entries.section', 'clinic', 'doctor'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($clinic !== null) {
            $recordsQuery->where('clinic_id', $clinic->id);
        } else {
            $recordsQuery->whereNull('clinic_id');
        }

        $records = $recordsQuery->get();

        return response()->json([
            'success' => true,
            'data' => ClinicalHistoryRecordResource::collection($records),
        ]);
    }

    /** @return array<int, int> */
    private function connectedPatientIds(User $doctor, Clinic $clinic): array
    {
        $appointmentIds = Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('doctor_user_id', $doctor->id)
            ->pluck('patient_profile_id');

        $intakeIds = PatientIntake::query()
            ->where('clinic_id', $clinic->id)
            ->where('requested_doctor_user_id', $doctor->id)
            ->pluck('patient_profile_id');

        $linkedIds = DB::table('doctor_patient_links')
            ->where('clinic_id', $clinic->id)
            ->where('doctor_user_id', $doctor->id)
            ->pluck('patient_profile_id');

        return $appointmentIds->merge($intakeIds)->merge($linkedIds)->unique()->all();
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
                ->exists()
            || DB::table('doctor_patient_links')
                ->where('clinic_id', $clinic->id)
                ->where('patient_profile_id', $patient->id)
                ->where('doctor_user_id', $doctor->id)
                ->exists();

        if (! $connected) {
            abort(403, 'No tienes un vínculo clínico con este paciente.');
        }
    }

    private function assertPatientAccessible(User $doctor, ?Clinic $clinic, PatientProfile $patient): void
    {
        if ($clinic === null) {
            $this->assertIndependentPatient($doctor, null, $patient);

            return;
        }

        $this->assertPatientConnected($doctor, $clinic, $patient);
    }

    private function assertIndependentPatient(User $doctor, ?Clinic $clinic, PatientProfile $patient): void
    {
        if ($clinic !== null || $patient->clinic_id !== null || $patient->owner_doctor_user_id !== $doctor->id) {
            abort(404, 'Paciente no encontrado.');
        }
    }
}
