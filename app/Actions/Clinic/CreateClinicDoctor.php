<?php

namespace App\Actions\Clinic;

use App\Enums\ClinicMembershipStatus;
use App\Enums\DoctorPracticeType;
use App\Enums\OnboardingStatus;
use App\Enums\RoleKey;
use App\Enums\UserStatus;
use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateClinicDoctor
{
    /**
     * @param array{
     *   name: string,
     *   email?: string|null,
     *   phone_number: string,
     *   country_code: string,
     *   medical_license: string,
     *   specialty_id: int,
     *   subspecialty_id?: int|null
     * } $data
     */
    public function handle(array $data, Clinic $clinic): User
    {
        try {
            $phone = PhoneNumber::from($data['phone_number'], $data['country_code']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phone_number' => [$exception->getMessage()]]);
        }

        $email = isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null;
        $email = $email === '' ? null : $email;
        $medicalLicense = isset($data['medical_license'])
            ? mb_strtoupper(trim((string) $data['medical_license']))
            : '';
        $specialtyId = filter_var($data['specialty_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $subspecialtyId = null;

        if (array_key_exists('subspecialty_id', $data) && $data['subspecialty_id'] !== null && $data['subspecialty_id'] !== '') {
            $subspecialtyId = filter_var($data['subspecialty_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        }

        if ($medicalLicense === '') {
            throw ValidationException::withMessages(['medical_license' => ['La cédula profesional es obligatoria.']]);
        }

        if (! is_int($specialtyId)) {
            throw ValidationException::withMessages(['specialty_id' => ['Selecciona una especialidad válida.']]);
        }

        if ($subspecialtyId !== null && ! is_int($subspecialtyId)) {
            throw ValidationException::withMessages(['subspecialty_id' => ['Selecciona una subespecialidad válida.']]);
        }

        try {
            return DB::transaction(function () use ($clinic, $data, $email, $medicalLicense, $phone, $specialtyId, $subspecialtyId): User {
                $specialty = Specialty::query()
                    ->whereKey($specialtyId)
                    ->whereNull('parent_id')
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if ($specialty === null) {
                    throw ValidationException::withMessages(['specialty_id' => ['Selecciona una especialidad válida.']]);
                }

                $subspecialty = null;
                if ($subspecialtyId !== null) {
                    $subspecialty = Specialty::query()
                        ->whereKey($subspecialtyId)
                        ->where('parent_id', $specialty->id)
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->first();

                    if ($subspecialty === null) {
                        throw ValidationException::withMessages(['subspecialty_id' => ['La subespecialidad no pertenece a la especialidad seleccionada.']]);
                    }
                }

                $duplicateUser = User::query()
                    ->where(function ($query) use ($email, $phone): void {
                        $query->where('phone_number', $phone->value);

                        if ($email !== null) {
                            $query->orWhereRaw('LOWER(email) = ?', [$email]);
                        }
                    })
                    ->lockForUpdate()
                    ->first();

                if ($duplicateUser !== null) {
                    $field = $email !== null && $duplicateUser->email !== null && mb_strtolower($duplicateUser->email) === $email
                        ? 'email'
                        : 'phone_number';

                    throw ValidationException::withMessages([$field => [$this->duplicateMessage($field)]]);
                }

                if (DoctorProfile::query()
                    ->whereRaw('UPPER(medical_license) = ?', [$medicalLicense])
                    ->lockForUpdate()
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'medical_license' => [$this->duplicateMessage('medical_license')],
                    ]);
                }

                $role = Role::query()
                    ->where('key', RoleKey::Doctor->value)
                    ->where('is_system', true)
                    ->first();

                if ($role === null) {
                    throw ValidationException::withMessages([
                        'role' => ['El rol de Médico no está configurado en el sistema.'],
                    ]);
                }

                $user = User::query()->create([
                    'name' => trim($data['name']),
                    'email' => $email,
                    'phone_number' => $phone->value,
                    'country_code' => $phone->countryCode,
                    'password' => Str::password(48),
                    'status' => UserStatus::Active,
                    'onboarding_status' => OnboardingStatus::Completed,
                    'phone_verified_at' => now(),
                ]);

                $user->roles()->sync([$role->id]);

                DoctorProfile::query()->create([
                    'user_id' => $user->id,
                    'clinic_id' => $clinic->id,
                    'medical_license' => $medicalLicense,
                    'specialty' => $specialty->name,
                    'specialty_id' => $specialty->id,
                    'subspecialty_id' => $subspecialty?->id,
                    'practice_type' => DoctorPracticeType::Clinic,
                ]);

                $clinic->memberships()->create([
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'status' => ClinicMembershipStatus::Active,
                ]);

                return $user;
            });
        } catch (QueryException $exception) {
            $field = $this->uniqueViolationField($exception);

            if ($field === null) {
                throw $exception;
            }

            throw ValidationException::withMessages([$field => [$this->duplicateMessage($field)]]);
        }
    }

    private function uniqueViolationField(QueryException $exception): ?string
    {
        $message = mb_strtolower($exception->getMessage());
        $code = (string) $exception->getCode();

        if (! in_array($code, ['19', '23000', '23505'], true)
            || (! str_contains($message, 'unique') && ! str_contains($message, 'duplicate'))) {
            return null;
        }

        return match (true) {
            str_contains($message, 'medical_license') => 'medical_license',
            str_contains($message, 'phone_number') => 'phone_number',
            str_contains($message, 'email') => 'email',
            default => null,
        };
    }

    private function duplicateMessage(string $field): string
    {
        return match ($field) {
            'medical_license' => 'La cédula profesional ya está registrada.',
            'email' => 'El correo ya está registrado.',
            default => 'El teléfono ya está registrado.',
        };
    }
}
