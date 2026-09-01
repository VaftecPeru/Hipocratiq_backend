<?php

namespace App\Support\Auth;

use App\Enums\RoleKey;

final class LoginRole
{
    /** @return list<string> */
    public static function exactStaffRoles(): array
    {
        return [
            RoleKey::SuperAdmin->value,
            RoleKey::ClinicAdmin->value,
            RoleKey::Receptionist->value,
            RoleKey::Doctor->value,
        ];
    }

    public static function isExactStaffRole(?string $role): bool
    {
        return in_array($role, self::exactStaffRoles(), true);
    }

    public static function normalize(?string $role): ?string
    {
        return $role === 'superadmin' ? RoleKey::SuperAdmin->value : $role;
    }
}
