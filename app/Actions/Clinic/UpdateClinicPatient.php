<?php

namespace App\Actions\Clinic;

use App\Models\PatientProfile;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class UpdateClinicPatient
{
    /** @param array<string, mixed> $data */
    public function handle(array $data, PatientProfile $patient): PatientProfile
    {
        return DB::transaction(function () use ($data, $patient): PatientProfile {
            $patient->loadMissing('user');
            $user = $patient->user;

            if ($user === null) {
                abort(404);
            }

            $userData = Arr::only($data, ['name', 'email']);

            if (array_key_exists('phone_number', $data) || array_key_exists('country_code', $data)) {
                try {
                    $phone = PhoneNumber::from(
                        (string) ($data['phone_number'] ?? $user->phone_number),
                        (string) ($data['country_code'] ?? $user->country_code),
                    );
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['phone_number' => [$exception->getMessage()]]);
                }

                if (User::query()
                    ->where('phone_number', $phone->value)
                    ->whereKeyNot($user->id)
                    ->lockForUpdate()
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'phone_number' => ['El teléfono ya pertenece a otro usuario.'],
                    ]);
                }

                $userData['phone_number'] = $phone->value;
                $userData['country_code'] = $phone->countryCode;
            }

            if ($userData !== []) {
                $user->fill($userData)->save();
            }

            $profileData = Arr::only($data, [
                'document_type',
                'document_number',
                'date_of_birth',
                'sex',
                'address',
                'emergency_contact',
                'insurance',
                'is_returning_patient',
                'whatsapp_consent',
            ]);

            if ($profileData !== []) {
                $patient->fill($profileData)->save();
            }

            return $patient->fresh(['user']) ?? $patient;
        });
    }
}
