<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Doctor\CreateDoctorAppointment;
use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DoctorAppointmentStatusUpdateRequest;
use App\Http\Requests\Api\V1\DoctorAppointmentStoreRequest;
use App\Http\Requests\Api\V1\DoctorAppointmentUpdateRequest;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\PatientProfile;
use App\Models\User;
use App\Support\Clinical\DoctorClinicScope;
use App\Support\Clinical\DoctorScheduling;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DoctorAppointmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : now()->startOfWeek(Carbon::MONDAY);
        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : $from->copy()->addDays(6)->endOfDay();

        if ($to->lessThan($from)) {
            return response()->json([
                'success' => false,
                'message' => 'El rango de fechas no es válido.',
            ], 422);
        }

        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $appointments = $this->appointmentsQuery($doctor, $clinic)
            ->whereBetween('scheduled_at', [$from, $to])
            ->orderBy('scheduled_at')
            ->get();
        $summary = $this->appointmentSummary($appointments);
        $summary['today_total'] = $appointments
            ->filter(fn (Appointment $appointment): bool => $appointment->scheduled_at?->isToday() === true)
            ->count();
        $summary['period_total'] = $appointments->count();

        return response()->json([
            'success' => true,
            'data' => $appointments
                ->map(fn (Appointment $appointment): array => $this->serializeAppointment($appointment))
                ->values(),
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'total' => $appointments->count(),
                'summary' => $summary,
            ],
        ]);
    }

    public function store(
        DoctorAppointmentStoreRequest $request,
        CreateDoctorAppointment $createAppointment,
    ): JsonResponse {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $data = $request->validated();
        $patient = PatientProfile::query()->findOrFail($data['patient_profile_id']);
        $this->assertPatientAccessible($doctor, $clinic, $patient);

        $appointment = $createAppointment->handle($doctor, $clinic, $patient, [
            'scheduled_at' => $data['scheduled_at'],
            'appointment_type' => $data['appointment_type'],
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializeAppointment($appointment->fresh([
                'patientProfile.user',
                'doctor.doctorProfile',
            ])),
        ], 201);
    }

    public function update(
        DoctorAppointmentUpdateRequest $request,
        Appointment $appointment,
        DoctorScheduling $scheduling,
    ): JsonResponse {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $data = $request->validated();
        $appointment = DB::transaction(function () use ($appointment, $clinic, $data, $doctor, $scheduling): Appointment {
            $profile = DoctorProfile::query()
                ->where('user_id', $doctor->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAppointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->assertAppointmentOwned($doctor, $clinic, $lockedAppointment);

            if (array_key_exists('scheduled_at', $data)) {
                $scheduling->assertBookable(
                    $profile,
                    $clinic,
                    CarbonImmutable::parse($data['scheduled_at']),
                    $lockedAppointment->id,
                );
            }

            $lockedAppointment->update($data);

            return $lockedAppointment->fresh([
                'patientProfile.user',
                'doctor.doctorProfile',
            ]);
        });

        return response()->json([
            'success' => true,
            'data' => $this->serializeAppointment($appointment),
        ]);
    }

    public function cancel(Request $request, Appointment $appointment, DoctorScheduling $scheduling): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $appointment = DB::transaction(function () use ($appointment, $clinic, $doctor, $scheduling): Appointment {
            DoctorProfile::query()
                ->where('user_id', $doctor->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAppointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->assertAppointmentOwned($doctor, $clinic, $lockedAppointment);

            if (in_array($lockedAppointment->status, [AppointmentStatus::Completed, AppointmentStatus::NoShow], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Una cita completada o marcada como no asistida no se puede cancelar.'],
                ]);
            }

            $scheduling->assertAppointmentStatusTransition($lockedAppointment->status, AppointmentStatus::Canceled);
            $lockedAppointment->update(['status' => AppointmentStatus::Canceled]);

            return $lockedAppointment->fresh([
                'patientProfile.user',
                'doctor.doctorProfile',
            ]);
        });

        return response()->json([
            'success' => true,
            'data' => $this->serializeAppointment($appointment),
        ]);
    }

    public function updateStatus(
        DoctorAppointmentStatusUpdateRequest $request,
        Appointment $appointment,
        DoctorScheduling $scheduling,
    ): JsonResponse {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $nextStatus = AppointmentStatus::from((string) $request->validated('status'));

        $appointment = DB::transaction(function () use ($appointment, $clinic, $doctor, $nextStatus, $scheduling): Appointment {
            DoctorProfile::query()
                ->where('user_id', $doctor->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAppointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->assertAppointmentOwned($doctor, $clinic, $lockedAppointment);
            $scheduling->assertAppointmentStatusTransition($lockedAppointment->status, $nextStatus);
            $lockedAppointment->update(['status' => $nextStatus]);

            return $lockedAppointment->fresh([
                'patientProfile.user',
                'doctor.doctorProfile',
            ]);
        });

        return response()->json([
            'success' => true,
            'data' => $this->serializeAppointment($appointment),
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

        if ($clinic !== null) {
            $query->where('clinic_id', $clinic->id);
        } else {
            $query->whereNull('clinic_id');
        }

        return $query;
    }

    private function assertPatientAccessible(User $doctor, ?Clinic $clinic, PatientProfile $patient): void
    {
        if ($clinic !== null) {
            if ($patient->clinic_id !== $clinic->id) {
                abort(404, 'Paciente no encontrado en la clínica del médico.');
            }

            return;
        }

        if ($patient->clinic_id !== null || $patient->owner_doctor_user_id !== $doctor->id) {
            abort(404, 'Paciente no encontrado.');
        }
    }

    private function assertAppointmentOwned(User $doctor, ?Clinic $clinic, Appointment $appointment): void
    {
        $sameClinic = $clinic === null
            ? $appointment->clinic_id === null
            : $appointment->clinic_id === $clinic->id;

        if (! $sameClinic || $appointment->doctor_user_id !== $doctor->id) {
            abort(404, 'Cita no encontrada.');
        }
    }

    /** @return array<string, int> */
    private function appointmentSummary(iterable $appointments): array
    {
        $collection = collect($appointments);

        return [
            'today_total' => $collection->count(),
            'confirmed' => $collection->where('status', AppointmentStatus::Confirmed)->count(),
            'pending' => $collection->where('status', AppointmentStatus::Pending)->count(),
            'waiting' => $collection->where('status', AppointmentStatus::Waiting)->count(),
            'in_consultation' => $collection->where('status', AppointmentStatus::InConsultation)->count(),
            'completed' => $collection->where('status', AppointmentStatus::Completed)->count(),
            'no_show' => $collection->where('status', AppointmentStatus::NoShow)->count(),
            'canceled' => $collection->where('status', AppointmentStatus::Canceled)->count(),
        ];
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
