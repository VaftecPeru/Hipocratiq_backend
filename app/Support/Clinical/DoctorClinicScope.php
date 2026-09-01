<?php

namespace App\Support\Clinical;

use App\Enums\DoctorPracticeType;
use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\User;

final class DoctorClinicScope
{
    public static function activeProfile(User $doctor): DoctorProfile
    {
        if (! $doctor->hasActiveDoctorAccess()) {
            abort(403, 'La credencial médica no tiene acceso clínico activo.');
        }

        return $doctor->doctorProfile()->with('clinic')->firstOrFail();
    }

    /**
     * Resolve the working clinic for an authenticated doctor.
     *
     * A doctor may only work inside their active clinic practice. Independent
     * doctors (no clinic assignment) cannot be scoped to a clinic, so any
     * clinic-scoped clinical endpoint declines rather than guessing.
     */
    public static function workingClinic(User $doctor): Clinic
    {
        $profile = self::activeProfile($doctor);

        if ($profile->practice_type !== DoctorPracticeType::Clinic || $profile->clinic_id === null || $profile->clinic === null) {
            abort(403, 'Esta funcionalidad requiere un médico con una clínica asignada.');
        }

        return $profile->clinic;
    }

    public static function clinicOrNull(User $doctor): ?Clinic
    {
        $profile = self::activeProfile($doctor);

        if ($profile->practice_type === DoctorPracticeType::Independent) {
            if ($profile->clinic_id !== null) {
                abort(403, 'El perfil independiente no puede tener una clínica asignada.');
            }

            return null;
        }

        if ($profile->clinic_id === null || $profile->clinic === null) {
            abort(403, 'Esta funcionalidad requiere un médico con una clínica asignada.');
        }

        return $profile->clinic;
    }

    public static function assertIndependent(User $doctor): void
    {
        $profile = self::activeProfile($doctor);

        if ($profile->practice_type !== DoctorPracticeType::Independent || $profile->clinic_id !== null) {
            abort(403, 'Esta funcionalidad requiere un médico independiente.');
        }
    }
}
