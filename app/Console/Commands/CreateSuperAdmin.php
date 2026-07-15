<?php

namespace App\Console\Commands;

use App\Enums\OnboardingStatus;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

#[Signature('app:create-super-admin {email : Correo del Super Admin} {phone : Teléfono E.164} {--name= : Nombre completo}')]
#[Description('Crea de forma segura una cuenta global de Super Administrador')]
class CreateSuperAdmin extends Command
{
    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        $name = (string) ($this->option('name') ?: 'Super Administrador');
        $password = (string) $this->secret('Contraseña');
        $passwordConfirmation = (string) $this->secret('Confirmar contraseña');

        try {
            $phoneNumber = PhoneNumber::fromE164((string) $this->argument('phone'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'phone_number' => $phoneNumber->value,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone_number' => ['required', 'unique:users,phone_number'],
            'password' => ['required', 'confirmed', 'max:72', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($name, $email, $phoneNumber, $password): User {
            $role = Role::query()->firstOrCreate(
                ['key' => RoleKey::SuperAdmin->value],
                [
                    'name' => 'Super Administrador',
                    'scope' => RoleScope::Global,
                    'is_system' => true,
                ],
            );

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'email_verified_at' => now(),
                'phone_number' => $phoneNumber->value,
                'country_code' => $phoneNumber->countryCode,
                'phone_verified_at' => now(),
                'password' => $password,
                'status' => UserStatus::Active,
                'onboarding_status' => OnboardingStatus::Completed,
            ]);

            $user->roles()->attach($role->id);

            return $user;
        });

        $this->info("Super Admin creado: {$user->email}");

        return self::SUCCESS;
    }
}
