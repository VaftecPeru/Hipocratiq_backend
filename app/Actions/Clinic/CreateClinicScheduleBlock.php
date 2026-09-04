<?php

namespace App\Actions\Clinic;

use App\Enums\ClinicMembershipStatus;
use App\Enums\RoleKey;
use App\Models\Clinic;
use App\Models\ClinicScheduleBlock;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateClinicScheduleBlock
{
    /**
     * @param array<string, mixed> $data
     */
    public function handle(
        array $data,
        Clinic $clinic,
        User $creator,
    ): ClinicScheduleBlock {
        $doctorUserId = isset($data['doctor_user_id'])
            ? (int) $data['doctor_user_id']
            : null;

        if (
            $doctorUserId !== null
            && ! $this->doctorBelongsToClinic($doctorUserId, $clinic)
        ) {
            throw ValidationException::withMessages([
                'doctor_user_id' => [
                    'El médico seleccionado no pertenece a esta clínica.',
                ],
            ]);
        }

        $appointmentQuery = Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('scheduled_at', '>=', $data['starts_at'])
            ->where('scheduled_at', '<', $data['ends_at']);

        if ($doctorUserId !== null) {
            $appointmentQuery->where(
                'doctor_user_id',
                $doctorUserId
            );
        }

        if ($appointmentQuery->exists()) {
            throw ValidationException::withMessages([
                'starts_at' => [
                    'No se puede bloquear este horario porque ya existen citas programadas en ese periodo.',
                ],
            ]);
        }

        $hasOverlappingBlock = ClinicScheduleBlock::query()
            ->where('clinic_id', $clinic->id)
            ->where(function ($query) use ($doctorUserId): void {
                if ($doctorUserId === null) {
                    return;
                }

                $query->whereNull('doctor_user_id')
                    ->orWhere('doctor_user_id', $doctorUserId);
            })
            ->where('starts_at', '<', $data['ends_at'])
            ->where('ends_at', '>', $data['starts_at'])
            ->exists();

        if ($hasOverlappingBlock) {
            throw ValidationException::withMessages([
                'starts_at' => [
                    'Ya existe un bloqueo que coincide con este horario.',
                ],
            ]);
        }

        return DB::transaction(function () use (
            $clinic,
            $creator,
            $doctorUserId,
            $data,
        ): ClinicScheduleBlock {
            return ClinicScheduleBlock::query()->create([
                'clinic_id' => $clinic->id,
                'doctor_user_id' => $doctorUserId,
                'created_by_user_id' => $creator->id,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'reason' => isset($data['reason'])
                    ? trim((string) $data['reason'])
                    : null,
            ]);
        });
    }

    private function doctorBelongsToClinic(
        int $doctorUserId,
        Clinic $clinic,
    ): bool {
        return DoctorProfile::query()
            ->where('user_id', $doctorUserId)
            ->where('clinic_id', $clinic->id)
            ->whereHas(
                'user.clinicMemberships',
                fn ($query) => $query
                    ->where('clinic_id', $clinic->id)
                    ->where(
                        'status',
                        ClinicMembershipStatus::Active
                    )
                    ->whereHas(
                        'role',
                        fn ($roleQuery) => $roleQuery
                            ->where(
                                'key',
                                RoleKey::Doctor->value
                            )
                    )
            )
            ->exists();
    }
}