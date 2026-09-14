<?php

namespace App\Actions\Doctor;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\PatientProfile;
use App\Models\User;
use App\Support\Clinical\DoctorScheduling;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CreateDoctorAppointment
{
    public function __construct(private DoctorScheduling $scheduling) {}

    /**
     * @param  array{scheduled_at: string, appointment_type: string, notes?: string|null}  $data
     */
    public function handle(User $doctor, ?Clinic $clinic, PatientProfile $patient, array $data): Appointment
    {
        return DB::transaction(function () use ($doctor, $clinic, $patient, $data): Appointment {
            $profile = DoctorProfile::query()
                ->where('user_id', $doctor->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->scheduling->assertBookable(
                $profile,
                $clinic,
                CarbonImmutable::parse($data['scheduled_at']),
            );

            return Appointment::query()->create([
                'clinic_id' => $clinic?->id,
                'patient_profile_id' => $patient->id,
                'doctor_user_id' => $doctor->id,
                'scheduled_at' => $data['scheduled_at'],
                'status' => $this->scheduling->initialAppointmentStatus($profile),
                'appointment_type' => $data['appointment_type'],
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }
}
