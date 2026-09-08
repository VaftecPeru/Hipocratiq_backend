<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Enums\ConsultationStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Consultation;
use App\Models\PatientProfile;
use App\Models\User;
use App\Support\Clinical\DoctorClinicScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $doctor = $request->user();
        $profile = DoctorClinicScope::activeProfile($doctor);
        $clinic = $profile->clinic;
        $date = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $validated['date'] ?? now()->toDateString(),
            (string) config('app.timezone', 'UTC'),
        );
        $dayStart = $date->startOfDay();
        $dayEnd = $date->endOfDay();
        $monthStart = $date->startOfMonth();
        $monthEnd = $date->endOfMonth();
        $todayAppointments = $this->appointmentsQuery($doctor, $clinic)
            ->whereBetween('scheduled_at', [$dayStart, $dayEnd])
            ->orderBy('scheduled_at')
            ->get();
        $monthConsultations = $this->consultationsQuery($doctor, $clinic)
            ->whereBetween('created_at', [$monthStart, $monthEnd]);
        $patientsQuery = $this->connectedPatientsQuery($doctor, $clinic);
        $patientsTotal = (clone $patientsQuery)->count();
        $frequentPatients = $patientsQuery
            ->withCount(['appointments as doctor_appointments_count' => function (Builder $query) use ($doctor, $clinic): void {
                $query->where('doctor_user_id', $doctor->id);
                if ($clinic === null) {
                    $query->whereNull('clinic_id');
                } else {
                    $query->where('clinic_id', $clinic->id);
                }
            }])
            ->orderByDesc('doctor_appointments_count')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
        $recentConsultations = $this->consultationsQuery($doctor, $clinic)
            ->with('patientProfile.user:id,name')
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get();
        $unsignedCompleted = (clone $monthConsultations)
            ->where('status', ConsultationStatus::Completed->value)
            ->whereNull('signed_at')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'generated_at' => now()->toIso8601String(),
                'date' => $date->toDateString(),
                'doctor' => [
                    'id' => $doctor->id,
                    'name' => $doctor->name,
                    'specialty' => $profile->specialty,
                    'practice_type' => $profile->practice_type->value,
                    'clinic' => $clinic?->only(['id', 'name']),
                ],
                'metrics' => [
                    'today_appointments' => $todayAppointments->count(),
                    'today_completed' => $todayAppointments->where('status', AppointmentStatus::Completed)->count(),
                    'today_pending' => $todayAppointments->where('status', AppointmentStatus::Pending)->count(),
                    'patients_total' => $patientsTotal,
                    'month_consultations' => $monthConsultations->count(),
                    'month_signed_consultations' => (clone $monthConsultations)->where('status', ConsultationStatus::Signed->value)->count(),
                ],
                'today_appointments' => $todayAppointments
                    ->map(fn (Appointment $appointment): array => $this->serializeAppointment($appointment))
                    ->values(),
                'recent_consultations' => $recentConsultations
                    ->map(fn (Consultation $consultation): array => [
                        'id' => $consultation->id,
                        'patient_name' => $consultation->patientProfile?->user?->name,
                        'chief_complaint' => $consultation->chief_complaint,
                        'status' => $consultation->status->value,
                        'status_label' => $consultation->status->label(),
                        'created_at' => $consultation->created_at?->toIso8601String(),
                    ])
                    ->values(),
                'frequent_patients' => $frequentPatients
                    ->map(fn (PatientProfile $patient): array => [
                        'id' => $patient->id,
                        'patient_code' => 'PAC-'.str_pad((string) $patient->id, 6, '0', STR_PAD_LEFT),
                        'name' => $patient->user?->name,
                        'visits' => (int) ($patient->doctor_appointments_count ?? 0),
                    ])
                    ->values(),
                'alerts' => [
                    'pending_appointments' => $todayAppointments->where('status', AppointmentStatus::Pending)->count(),
                    'unsigned_completed_consultations' => $unsignedCompleted,
                ],
            ],
        ]);
    }

    /** @return Builder<Appointment> */
    private function appointmentsQuery(User $doctor, ?Clinic $clinic): Builder
    {
        $query = Appointment::query()
            ->where('doctor_user_id', $doctor->id)
            ->with([
                'patientProfile.user:id,name,email,phone_number',
                'doctor:id,name',
                'doctor.doctorProfile:id,user_id,specialty',
            ]);

        return $clinic === null
            ? $query->whereNull('clinic_id')
            : $query->where('clinic_id', $clinic->id);
    }

    /** @return Builder<Consultation> */
    private function consultationsQuery(User $doctor, ?Clinic $clinic): Builder
    {
        $query = Consultation::query()->where('doctor_user_id', $doctor->id);

        return $clinic === null
            ? $query->whereNull('clinic_id')
            : $query->where('clinic_id', $clinic->id);
    }

    /** @return Builder<PatientProfile> */
    private function connectedPatientsQuery(User $doctor, ?Clinic $clinic): Builder
    {
        $query = PatientProfile::query()->with('user:id,name');

        if ($clinic === null) {
            return $query->whereNull('clinic_id')->where('owner_doctor_user_id', $doctor->id);
        }

        return $query
            ->where('clinic_id', $clinic->id)
            ->where(function (Builder $patientQuery) use ($doctor, $clinic): void {
                $patientQuery
                    ->whereHas('appointments', function (Builder $appointmentQuery) use ($doctor, $clinic): void {
                        $appointmentQuery->where('doctor_user_id', $doctor->id)->where('clinic_id', $clinic->id);
                    })
                    ->orWhereHas('intakes', function (Builder $intakeQuery) use ($doctor, $clinic): void {
                        $intakeQuery->where('requested_doctor_user_id', $doctor->id)->where('clinic_id', $clinic->id);
                    });
            });
    }

    /** @return array<string, mixed> */
    private function serializeAppointment(Appointment $appointment): array
    {
        $patient = $appointment->patientProfile?->user;
        $doctor = $appointment->doctor;

        return [
            'id' => $appointment->id,
            'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
            'scheduled_date' => $appointment->scheduled_at?->toDateString(),
            'time' => $appointment->scheduled_at?->format('H:i'),
            'appointment_type' => $appointment->appointment_type,
            'notes' => $appointment->notes,
            'status' => $appointment->status->value,
            'status_label' => $appointment->status->label(),
            'patient' => [
                'id' => $appointment->patient_profile_id,
                'patient_code' => 'PAC-'.str_pad((string) $appointment->patient_profile_id, 6, '0', STR_PAD_LEFT),
                'name' => $patient?->name,
                'phone_number' => $patient?->phone_number,
            ],
            'doctor' => $doctor === null ? null : [
                'id' => $doctor->id,
                'name' => $doctor->name,
                'specialty' => $doctor->doctorProfile?->specialty,
            ],
        ];
    }
}
