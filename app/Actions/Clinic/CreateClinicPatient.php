<?php

namespace App\Actions\Clinic;

use App\Enums\OnboardingStatus;
use App\Enums\UserStatus;
use App\Models\Clinic;
use App\Models\PatientProfile;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateClinicPatient
{
    /** @param array{name: string, email?: string|null, phone_number: string, country_code: string} $data */
    public function handle(array $data, Clinic $clinic): PatientProfile
    {
        try {
            $phone = PhoneNumber::from($data['phone_number'], $data['country_code']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phone_number' => [$exception->getMessage()]]);
        }

        $email = isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null;

        return DB::transaction(function () use ($clinic, $data, $email, $phone): PatientProfile {
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

            return $user->patientProfile()->create(['clinic_id' => $clinic->id]);
        });
    }
}
