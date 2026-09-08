<?php

namespace App\Actions\Clinic;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicMembershipStatus;
use App\Enums\RoleKey;
use App\Models\Appointment;
use App\Models\Clinic;
use Illuminate\Validation\ValidationException;

final class CreateClinicAppointment
{
    /**
     * @param array{
     *   patient_profile_id: int,
     *   doctor_user_id: int,
     *   scheduled_at: string,
     *   appointment_type: string,
     *   notes?: string|null
     * } $data
     */
    public function handle(array $data, Clinic $clinic): Appointment
    {
        $patientBelongsToClinic = $clinic->patientProfiles()
            ->whereKey($data['patient_profile_id'])
            ->exists();

        if (! $patientBelongsToClinic) {
            throw ValidationException::withMessages([
                'patient_profile_id' => ['El paciente no pertenece a esta clínica.'],
            ]);
        }

        $doctorIsClinicMember = $clinic->memberships()
            ->where('user_id', $data['doctor_user_id'])
            ->where('status', ClinicMembershipStatus::Active->value)
            ->whereHas('role', fn ($query) => $query->where('key', RoleKey::Doctor->value))
            ->exists();

        if (! $doctorIsClinicMember) {
            throw ValidationException::withMessages([
                'doctor_user_id' => ['El médico no es miembro activo de esta clínica.'],
            ]);
        }

        $appointment = Appointment::query()->create([
            'clinic_id' => $clinic->id,
            'patient_profile_id' => $data['patient_profile_id'],
            'doctor_user_id' => $data['doctor_user_id'],
            'scheduled_at' => $data['scheduled_at'],
            'appointment_type' => $data['appointment_type'],
            'notes' => $data['notes'] ?? null,
            'status' => AppointmentStatus::Pending,
        ]);

        return $appointment->load([
            'patientProfile.user:id,name,email,phone_number',
            'doctor:id,name',
            'doctor.doctorProfile:id,user_id,specialty',
        ]);
    }
}
