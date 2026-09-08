<?php

namespace App\Actions\Doctor;

use App\Enums\OnboardingStatus;
use App\Enums\UserStatus;
use App\Models\PatientProfile;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateIndependentDoctorPatient
{
    /** @param array<string, mixed> $data */
    public function handle(array $data, User $doctor): PatientProfile
    {
        try {
            $phone = PhoneNumber::from($data['phone_number'], $data['country_code']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phone_number' => [$exception->getMessage()]]);
        }

        $email = isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null;

        return DB::transaction(function () use ($data, $doctor, $email, $phone): PatientProfile {
            $existingUser = User::query()
                ->where('phone_number', $phone->value)
                ->when($email !== null, fn ($query) => $query->orWhere('email', $email))
                ->lockForUpdate()
                ->exists();

            if ($existingUser) {
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

            return $user->patientProfile()->create([
                'clinic_id' => null,
                'owner_doctor_user_id' => $doctor->id,
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
        });
    }
}
