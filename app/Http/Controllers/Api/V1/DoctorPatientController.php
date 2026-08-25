<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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

class DoctorPatientController extends Controller
{
    public function patients(Request $request): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::workingClinic($doctor);

        $patientIds = $this->connectedPatientIds($doctor, $clinic);

        if ($patientIds === []) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $patients = PatientProfile::query()
            ->where('clinic_id', $clinic->id)
            ->whereIn('id', $patientIds)
            ->with(['user', 'clinic'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => DoctorPatientResource::collection($patients),
        ]);
    }

    public function clinicalHistory(Request $request, PatientProfile $patient): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::workingClinic($doctor);
        $this->assertPatientConnected($doctor, $clinic, $patient);

        $records = $patient->clinicalHistoryRecords()
            ->where('clinic_id', $clinic->id)
            ->where('doctor_user_id', $doctor->id)
            ->with(['entries.section', 'clinic', 'doctor'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

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

        return $appointmentIds->merge($intakeIds)->unique()->all();
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
}
