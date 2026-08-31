<?php

namespace App\Actions\Clinic;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicMembershipStatus;
use App\Enums\RoleKey;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\PatientProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateClinicAppointment
{
    /**
     * @param array<string, mixed> $data
     */
    public function handle(array $data, Clinic $clinic): Appointment
    {
        $patientProfileId = (int) $data['patient_profile_id'];

        if (! $this->patientBelongsToClinic($patientProfileId, $clinic)) {
            throw ValidationException::withMessages([
                'patient_profile_id' => [
                    'El paciente seleccionado no pertenece a esta clínica.',
                ],
            ]);
        }

        $doctorUserId = isset($data['doctor_user_id'])
            ? (int) $data['doctor_user_id']
            : null;

        if (
            $doctorUserId !== null
            && ! $this->doctorBelongsToClinic($doctorUserId, $clinic)
        ) {
            throw ValidationException::withMessages([
                'doctor_user_id' => [
                    'El médico seleccionado no pertenece a esta clínica.',
                ],
            ]);
        }

        return DB::transaction(function () use (
            $data,
            $clinic,
            $patientProfileId,
            $doctorUserId
        ): Appointment {
            return Appointment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_profile_id' => $patientProfileId,
                'doctor_user_id' => $doctorUserId,
                'scheduled_at' => $data['scheduled_at'],
                'status' => AppointmentStatus::Pending,
                'appointment_type' => $data['appointment_type'],
                'notes' => isset($data['notes'])
                    ? trim((string) $data['notes'])
                    : null,
            ]);
        });
    }

    private function patientBelongsToClinic(
        int $patientProfileId,
        Clinic $clinic
    ): bool {
        return PatientProfile::query()
            ->whereKey($patientProfileId)
            ->where('clinic_id', $clinic->id)
            ->exists();
    }

    private function doctorBelongsToClinic(
        int $doctorUserId,
        Clinic $clinic
    ): bool {
        return DoctorProfile::query()
            ->where('user_id', $doctorUserId)
            ->where('clinic_id', $clinic->id)
            ->whereHas(
                'user.clinicMemberships',
                fn ($query) => $query
                    ->where('clinic_id', $clinic->id)
                    ->where('status', ClinicMembershipStatus::Active)
                    ->whereHas(
                        'role',
                        fn ($roleQuery) => $roleQuery
                            ->where('key', RoleKey::Doctor->value)
                    )
            )
            ->exists();
    }
}