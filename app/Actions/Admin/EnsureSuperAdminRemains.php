<?php

namespace App\Actions\Admin;

use App\Enums\RoleKey;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class EnsureSuperAdminRemains
{
    public function lockSentinel(): void
    {
        Role::query()
            ->where('key', RoleKey::SuperAdmin->value)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function handle(User $user, string $attribute): void
    {
        if ($user->status !== UserStatus::Active || ! $user->hasRole(RoleKey::SuperAdmin)) {
            return;
        }

        $activeSuperAdmins = User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('roles', fn ($query) => $query->where('key', RoleKey::SuperAdmin->value))
            ->count();

        if ($activeSuperAdmins <= 1) {
            throw ValidationException::withMessages([
                $attribute => ['No puedes retirar el acceso al último Superadministrador activo.'],
            ]);
        }
    }
}
