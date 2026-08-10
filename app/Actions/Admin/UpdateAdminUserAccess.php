<?php

namespace App\Actions\Admin;

use App\Enums\AdminAssignableRole;
use App\Enums\ClinicMembershipStatus;
use App\Enums\DoctorPracticeType;
use App\Enums\RoleKey;
use App\Enums\UserStatus;
use App\Models\Clinic;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateAdminUserAccess
{
    /**
     * @return array{user: User, before: array<string, mixed>, after: array<string, mixed>, changed: bool}
     */
    public function handle(
        User $actor,
        User $user,
        AdminAssignableRole $role,
        ?Clinic $clinic,
    ): array {
        if ($actor->is($user)) {
            throw ValidationException::withMessages([
                'role' => ['No puedes modificar tu propio rol o alcance mientras tu sesión está activa.'],
            ]);
        }

        return DB::transaction(function () use ($user, $role, $clinic): array {
            $lockedUser = User::query()
                ->with(['roles:id,key,name', 'clinicMemberships.clinic:id,name,address', 'doctorProfile.clinic:id,name,address', 'patientProfile.clinic:id,name,address'])
                ->lockForUpdate()
                ->findOrFail($user->getKey());

            $before = $this->snapshot($lockedUser);
            $this->ensureLastSuperAdminIsPreserved($lockedUser, $role);
            $this->applyRole($lockedUser, $role, $clinic);

            $lockedUser->load(['roles:id,key,name', 'clinicMemberships.clinic:id,name,address', 'doctorProfile.clinic:id,name,address', 'patientProfile.clinic:id,name,address']);
            $after = $this->snapshot($lockedUser);
            $changed = $before !== $after;

            if ($changed) {
                $lockedUser->authenticationSessions()->delete();
                $lockedUser->tokens()->delete();
            }

            return [
                'user' => $lockedUser,
                'before' => $before,
                'after' => $after,
                'changed' => $changed,
            ];
        });
    }

    private function applyRole(User $user, AdminAssignableRole $role, ?Clinic $clinic): void
    {
        if ($role === AdminAssignableRole::SuperAdmin) {
            $systemRole = $this->systemRole(RoleKey::SuperAdmin);
            $user->roles()->sync([$systemRole->getKey()]);
            $user->clinicMemberships()->update(['status' => ClinicMembershipStatus::Suspended->value]);

            return;
        }

        if ($clinic === null) {
            throw ValidationException::withMessages(['clinic_id' => ['Selecciona una clínica activa.']]);
        }

        if ($role === AdminAssignableRole::Patient) {
            if ($user->patientProfile === null) {
                throw ValidationException::withMessages([
                    'role' => ['Este usuario no tiene un perfil de paciente y no puede recibir ese rol.'],
                ]);
            }

            $user->roles()->detach();
            $user->patientProfile()->update(['clinic_id' => $clinic->getKey()]);
            $user->clinicMemberships()->update(['status' => ClinicMembershipStatus::Suspended->value]);

            return;
        }

        $roleKey = $role === AdminAssignableRole::ClinicAdmin ? RoleKey::ClinicAdmin : RoleKey::Doctor;
        $systemRole = $this->systemRole($roleKey);

        if ($role === AdminAssignableRole::Doctor) {
            if ($user->doctorProfile === null) {
                throw ValidationException::withMessages([
                    'role' => ['Este usuario no tiene un perfil médico y no puede recibir ese rol.'],
                ]);
            }

            $user->doctorProfile()->update([
                'clinic_id' => $clinic->getKey(),
                'practice_type' => DoctorPracticeType::Clinic->value,
            ]);
        }

        $user->roles()->sync([$systemRole->getKey()]);
        $user->clinicMemberships()
            ->where('clinic_id', '!=', $clinic->getKey())
            ->update(['status' => ClinicMembershipStatus::Suspended->value]);
        $user->clinicMemberships()->updateOrCreate(
            ['clinic_id' => $clinic->getKey()],
            ['role_id' => $systemRole->getKey(), 'status' => ClinicMembershipStatus::Active->value],
        );
    }

    private function systemRole(RoleKey $role): Role
    {
        $systemRole = Role::query()->where('key', $role->value)->where('is_system', true)->first();

        if ($systemRole === null) {
            throw ValidationException::withMessages([
                'role' => ["El rol {$role->value} no está configurado en el sistema."],
            ]);
        }

        return $systemRole;
    }

    private function ensureLastSuperAdminIsPreserved(User $user, AdminAssignableRole $nextRole): void
    {
        if (! $user->hasRole(RoleKey::SuperAdmin) || $nextRole === AdminAssignableRole::SuperAdmin) {
            return;
        }

        $activeSuperAdmins = User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('roles', fn ($query) => $query->where('key', RoleKey::SuperAdmin->value))
            ->lockForUpdate()
            ->pluck('users.id')
            ->count();

        if ($activeSuperAdmins <= 1) {
            throw ValidationException::withMessages([
                'role' => ['No puedes retirar el rol al último Superadministrador activo.'],
            ]);
        }
    }

    /** @return array{role: string, clinic_id: int|null} */
    private function snapshot(User $user): array
    {
        $user->loadMissing(['roles:id,key', 'clinicMemberships.clinic:id', 'doctorProfile.clinic:id', 'patientProfile.clinic:id']);
        $roleKeys = $user->roles->pluck('key');
        $role = collect([RoleKey::SuperAdmin, RoleKey::ClinicAdmin, RoleKey::Doctor])
            ->first(fn (RoleKey $candidate): bool => $roleKeys->contains($candidate->value));
        $activeMembership = $user->clinicMemberships
            ->first(fn ($membership): bool => $membership->status === ClinicMembershipStatus::Active);

        return [
            'role' => $role?->value ?? ($user->patientProfile !== null ? AdminAssignableRole::Patient->value : 'unassigned'),
            'clinic_id' => $activeMembership?->clinic_id
                ?? $user->doctorProfile?->clinic_id
                ?? $user->patientProfile?->clinic_id,
        ];
    }
}
