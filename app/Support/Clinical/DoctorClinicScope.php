<?php

namespace App\Support\Clinical;

use App\Enums\DoctorPracticeType;
use App\Models\Clinic;
use App\Models\User;

final class DoctorClinicScope
{
    /**
     * Resolve the working clinic for an authenticated doctor.
     *
     * A doctor may only work inside their active clinic practice. Independent
     * doctors (no clinic assignment) cannot be scoped to a clinic, so any
     * clinic-scoped clinical endpoint declines rather than guessing.
     */
    public static function workingClinic(User $doctor): Clinic
    {
        if (! $doctor->hasActiveDoctorAccess()) {
            abort(403, 'La credencial médica no tiene acceso clínico activo.');
        }

        $profile = $doctor->doctorProfile()->with('clinic')->first();

        if ($profile === null || $profile->practice_type !== DoctorPracticeType::Clinic || $profile->clinic_id === null) {
            abort(403, 'Esta funcionalidad requiere un médico con una clínica asignada.');
        }

        return $profile->clinic;
    }
}
