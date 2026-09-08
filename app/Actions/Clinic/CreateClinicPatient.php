<?php

namespace App\Actions\Clinic;

use App\Enums\ClinicMembershipStatus;
use App\Enums\OnboardingStatus;
use App\Enums\RoleKey;
use App\Enums\UserStatus;
use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\PatientIntake;
use App\Models\PatientProfile;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateClinicPatient
{
    /** @param array<string, mixed> $data */
    public function handle(array $data, Clinic $clinic, User $registrar): PatientProfile
    {
        try {
            $phone = PhoneNumber::from($data['phone_number'], $data['country_code']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phone_number' => [$exception->getMessage()]]);
        }

        $email = isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null;

        return DB::transaction(function () use ($clinic, $data, $email, $phone, $registrar): PatientProfile {
            if (User::query()->where('phone_number', $phone->value)->when(
                $email !== null,
                fn ($query) => $query->orWhere('email', $email),
            )->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'phone_number' => ['El correo o teléfono ya pertenece a otro usuario.'],
                ]);
            }

            $user = User::query()->create([
                'name' => trim($data['name']),
                'email' => $email,
                'phone_number' => $phone->value,
                'country_code' => $phone->countryCode,
                'password' => null,
                'status' => UserStatus::Active,
                'onboarding_status' => OnboardingStatus::Completed,
                'phone_verified_at' => now(),
            ]);

            $patient = $user->patientProfile()->create([
                'clinic_id' => $clinic->id,
                'document_type' => $data['document_type'] ?? null,
                'document_number' => $data['document_number'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'sex' => $data['sex'] ?? null,
                'address' => $data['address'] ?? null,
                'emergency_contact' => $data['emergency_contact'] ?? null,
                'insurance' => $data['insurance'] ?? null,
                'is_returning_patient' => $data['is_returning_patient'] ?? false,
                'whatsapp_consent' => $data['whatsapp_consent'] ?? false,
            ]);

            if (array_key_exists('objective', $data)) {
                $requestedDoctorId = $data['requested_doctor_user_id'] ?? null;
                if ($requestedDoctorId !== null && ! $this->belongsToClinic((int) $requestedDoctorId, $clinic)) {
                    throw ValidationException::withMessages(['requested_doctor_user_id' => ['El médico solicitado no pertenece a esta clínica.']]);
                }

                PatientIntake::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_profile_id' => $patient->id,
                    'registered_by_user_id' => $registrar->id,
                    'requested_doctor_user_id' => $requestedDoctorId,
                    'specialty_id' => $data['specialty_id'] ?? null,
                    'subspecialty_id' => $data['subspecialty_id'] ?? null,
                    'reason_category' => $data['reason_category'] ?? null,
                    'objective' => $data['objective'],
                    'observations' => $data['observations'] ?? null,
                    'temperature_c' => isset($data['temperature_c']) ? (float) $data['temperature_c'] : null,
                    'systolic_bp' => $data['systolic_bp'] ?? null,
                    'diastolic_bp' => $data['diastolic_bp'] ?? null,
                    'heart_rate' => $data['heart_rate'] ?? null,
                    'respiratory_rate' => $data['respiratory_rate'] ?? null,
                    'oxygen_saturation' => $data['oxygen_saturation'] ?? null,
                    'triage_priority' => $data['triage_priority'] ?? null,
                    'triage_notes' => $data['triage_notes'] ?? null,
                ]);
            }

            return $patient;
        });
    }

    private function belongsToClinic(int $doctorUserId, Clinic $clinic): bool
    {
        return DoctorProfile::query()
            ->where('user_id', $doctorUserId)
            ->where('clinic_id', $clinic->id)
            ->whereHas('user.clinicMemberships', fn ($query) => $query
                ->where('clinic_id', $clinic->id)
                ->where('status', ClinicMembershipStatus::Active)
                ->whereHas('role', fn ($roleQuery) => $roleQuery->where('key', RoleKey::Doctor->value)))
            ->exists();
    }
}
