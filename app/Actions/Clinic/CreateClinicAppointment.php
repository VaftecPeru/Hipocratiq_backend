<?php

namespace App\Actions\Clinic;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Support\Clinical\DoctorScheduling;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateClinicAppointment
{
    public function __construct(private readonly DoctorScheduling $scheduling) {}

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
        $appointment = DB::transaction(function () use ($data, $clinic): Appointment {
            $patientBelongsToClinic = $clinic->patientProfiles()
                ->whereKey($data['patient_profile_id'])
                ->exists();

            if (! $patientBelongsToClinic) {
                throw ValidationException::withMessages([
                    'patient_profile_id' => ['El paciente no pertenece a esta clínica.'],
                ]);
            }

            $doctorProfile = $this->scheduling->resolveClinicDoctor($clinic, $data['doctor_user_id'], true);
            $scheduledAt = CarbonImmutable::parse($data['scheduled_at']);
            $this->scheduling->assertBookable($doctorProfile, $clinic, $scheduledAt);

            return Appointment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_profile_id' => $data['patient_profile_id'],
                'doctor_user_id' => $data['doctor_user_id'],
                'scheduled_at' => $scheduledAt,
                'appointment_type' => $data['appointment_type'],
                'notes' => $data['notes'] ?? null,
                'status' => $this->scheduling->initialAppointmentStatus($doctorProfile),
            ]);
        });

        return $appointment->load([
            'patientProfile.user:id,name,email,phone_number',
            'doctor:id,name',
            'doctor.doctorProfile:id,user_id,specialty',
        ]);
    }
}
