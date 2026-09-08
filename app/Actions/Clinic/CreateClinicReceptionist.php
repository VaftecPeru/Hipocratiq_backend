<?php

namespace App\Actions\Clinic;

use App\Enums\ClinicMembershipStatus;
use App\Enums\OnboardingStatus;
use App\Enums\RoleKey;
use App\Enums\UserStatus;
use App\Models\Clinic;
use App\Models\Role;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateClinicReceptionist
{
    /** @param array{name: string, email?: string|null, phone_number: string, country_code: string} $data */
    public function handle(array $data, Clinic $clinic): User
    {
        try {
            $phone = PhoneNumber::from($data['phone_number'], $data['country_code']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phone_number' => [$exception->getMessage()]]);
        }

        $email = isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null;

        return DB::transaction(function () use ($clinic, $data, $email, $phone): User {
            if (User::query()->where('phone_number', $phone->value)->when(
                $email !== null,
                fn ($query) => $query->orWhere('email', $email),
            )->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'phone_number' => ['El correo o teléfono ya pertenece a otro usuario.'],
                ]);
            }

            $role = Role::query()
                ->where('key', RoleKey::Receptionist->value)
                ->where('is_system', true)
                ->first();

            if ($role === null) {
                throw ValidationException::withMessages([
                    'role' => ['El rol de Recepcionista no está configurado en el sistema.'],
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
            $clinic->memberships()->create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'status' => ClinicMembershipStatus::Active,
            ]);

            return $user;
        });
    }
}
