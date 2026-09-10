<?php

namespace App\Actions\Clinic;

use App\Enums\RoleKey;
use App\Models\Clinic;
use App\Models\ReceptionistInvitation;
use App\Models\Role;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateReceptionistInvitation
{
    /** @param array{name: string, email?: string|null, phone_number: string, country_code: string} $data */
    /** @return array{invitation: ReceptionistInvitation, token: string} */
    public function handle(array $data, Clinic $clinic, User $invitedBy): array
    {
        try {
            $phone = PhoneNumber::from($data['phone_number'], $data['country_code']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phone_number' => [$exception->getMessage()]]);
        }

        $email = isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null;
        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($clinic, $invitedBy, $data, $email, $phone, $token): ReceptionistInvitation {
            $userExists = User::query()
                ->where('phone_number', $phone->value)
                ->when($email !== null, fn ($query) => $query->orWhere('email', $email))
                ->lockForUpdate()
                ->exists();

            if ($userExists) {
                throw ValidationException::withMessages([
                    'phone_number' => ['El correo o teléfono ya pertenece a otro usuario.'],
                ]);
            }

            $pendingInvitation = ReceptionistInvitation::query()
                ->where('clinic_id', $clinic->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->where(function ($query) use ($phone, $email): void {
                    $query->where('phone_number', $phone->value);

                    if ($email !== null) {
                        $query->orWhere('email', $email);
                    }
                })
                ->lockForUpdate()
                ->exists();

            if ($pendingInvitation) {
                throw ValidationException::withMessages([
                    'phone_number' => ['Ya existe una invitación activa para este contacto.'],
                ]);
            }

            if (! Role::query()->where('key', RoleKey::Receptionist->value)->where('is_system', true)->exists()) {
                throw ValidationException::withMessages([
                    'role' => ['El rol de Recepcionista no está configurado en el sistema.'],
                ]);
            }

            return ReceptionistInvitation::query()->create([
                'clinic_id' => $clinic->id,
                'invited_by_user_id' => $invitedBy->id,
                'name' => trim($data['name']),
                'email' => $email,
                'phone_number' => $phone->value,
                'country_code' => $phone->countryCode,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(config('authentication.invitation.ttl_minutes')),
            ]);
        });

        return ['invitation' => $invitation, 'token' => $token];
    }
}
