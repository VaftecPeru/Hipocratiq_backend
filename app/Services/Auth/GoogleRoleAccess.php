<?php

namespace App\Services\Auth;

use App\Enums\AuthenticationContext;
use App\Enums\RoleKey;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class GoogleRoleAccess
{
    public function context(User $user, string $requestedRole): AuthenticationContext
    {
        if ($user->status !== UserStatus::Active) {
            $this->deny();
        }

        return match ($requestedRole) {
            RoleKey::SuperAdmin->value => $user->hasRole(RoleKey::SuperAdmin)
                ? AuthenticationContext::Staff
                : $this->deny(),
            RoleKey::ClinicAdmin->value => $user->hasActiveClinicAdminMembership()
                ? AuthenticationContext::ClinicAdmin
                : $this->deny(),
            RoleKey::Receptionist->value => $user->hasActiveReceptionistAccess()
                ? AuthenticationContext::Receptionist
                : $this->deny(),
            RoleKey::Doctor->value => $user->hasActiveDoctorAccess()
                ? AuthenticationContext::Doctor
                : $this->deny(),
            AuthenticationContext::Patient->value => $this->isPatientOnly($user)
                ? AuthenticationContext::Patient
                : $this->deny(),
            default => $this->deny(),
        };
    }

    private function isPatientOnly(User $user): bool
    {
        return $user->patientProfile()->exists()
            && ! $user->hasRole(RoleKey::SuperAdmin)
            && ! $user->hasActiveClinicAdminMembership()
            && ! $user->hasActiveReceptionistAccess()
            && ! $user->hasActiveDoctorAccess();
    }

    private function deny(): never
    {
        throw ValidationException::withMessages([
            'requested_role' => ['La cuenta de Google no corresponde al rol seleccionado.'],
        ]);
    }
}
