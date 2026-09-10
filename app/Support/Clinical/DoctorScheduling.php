<?php

namespace App\Support\Clinical;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicMembershipStatus;
use App\Enums\ConsultationStatus;
use App\Enums\RoleKey;
use App\Enums\UserStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicScheduleBlock;
use App\Models\DoctorProfile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class DoctorScheduling
{
    /** @var list<string> */
    private const ACTIVE_APPOINTMENT_STATUSES = [
        'pending',
        'confirmed',
        'waiting',
        'in_consultation',
    ];

    /** @return array<string, mixed> */
    public function preferences(DoctorProfile $profile): array
    {
        return DoctorPreferences::resolve($profile->preferences);
    }

    public function initialAppointmentStatus(DoctorProfile $profile): AppointmentStatus
    {
        return ($this->preferences($profile)['auto_confirm_appointments'] ?? false) === true
            ? AppointmentStatus::Confirmed
            : AppointmentStatus::Pending;
    }

    public function resolveClinicDoctor(Clinic $clinic, int $doctorUserId, bool $lockForUpdate = false): DoctorProfile
    {
        $profile = DoctorProfile::query()
            ->where('user_id', $doctorUserId)
            ->where('clinic_id', $clinic->id)
            ->whereHas('user', function (Builder $query) use ($clinic): void {
                $query
                    ->where('status', UserStatus::Active->value)
                    ->whereHas('clinicMemberships', function (Builder $membershipQuery) use ($clinic): void {
                        $membershipQuery
                            ->where('clinic_id', $clinic->id)
                            ->where('status', ClinicMembershipStatus::Active->value)
                            ->whereHas('role', fn (Builder $roleQuery): Builder => $roleQuery->where('key', RoleKey::Doctor->value));
                    });
            })
            ->when($lockForUpdate, fn (Builder $query): Builder => $query->lockForUpdate())
            ->first();

        if ($profile === null) {
            throw ValidationException::withMessages([
                'doctor_user_id' => ['El médico no es un miembro activo de esta clínica.'],
            ]);
        }

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    public function availability(DoctorProfile $profile, ?Clinic $clinic, CarbonImmutable $date): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $date = $date->setTimezone($timezone);
        $preferences = $this->preferences($profile);
        $dayKey = strtolower($date->format('l'));
        $schedule = $this->scheduleFor($preferences, $date);
        $duration = (int) $preferences['appointment_duration_minutes'];
        $buffer = (int) $preferences['buffer_minutes'];

        if ($schedule === null) {
            return [
                'date' => $date->toDateString(),
                'weekday' => $dayKey,
                'is_working_day' => false,
                'working_hours' => null,
                'duration_minutes' => $duration,
                'buffer_minutes' => $buffer,
                'available_slots' => 0,
                'slots' => [],
            ];
        }

        $appointments = $this->appointmentsFor($profile->user_id, $clinic)
            ->where('scheduled_at', '>=', $schedule['start']->subMinutes($duration + $buffer))
            ->where('scheduled_at', '<', $schedule['end']->addMinutes($buffer))
            ->orderBy('scheduled_at')
            ->get();
        $now = CarbonImmutable::now($schedule['start']->getTimezone());
        $blocks = $clinic === null ? collect() : $this->scheduleBlocksFor($profile->user_id, $clinic)
            ->where('starts_at', '<', $schedule['end'])
            ->where('ends_at', '>', $schedule['start'])
            ->get();
        $slots = [];
        $slotStart = $schedule['start'];

        while ($slotStart->copy()->addMinutes($duration)->lessThanOrEqualTo($schedule['end'])) {
            $slotEnd = $slotStart->copy()->addMinutes($duration);
            $blockingAppointment = $this->blockingAppointment($appointments, $slotStart, $slotEnd, $buffer);
            $isPast = $slotStart->lessThan($now);
            $isBlocked = $blocks->contains(fn (ClinicScheduleBlock $block): bool =>
                $slotStart->lessThan($block->ends_at) && $slotEnd->greaterThan($block->starts_at));

            $slots[] = [
                'time' => $slotStart->format('H:i'),
                'start' => $slotStart->toIso8601String(),
                'end' => $slotEnd->toIso8601String(),
                'available' => ! $isPast && ! $isBlocked && $blockingAppointment === null,
                'reason' => $isPast ? 'past' : ($isBlocked ? 'blocked' : ($blockingAppointment === null ? null : 'conflict')),
                'appointment_id' => $blockingAppointment?->id,
            ];
            $slotStart = $slotStart->addMinutes($duration + $buffer);
        }

        return [
            'date' => $date->toDateString(),
            'weekday' => $dayKey,
            'is_working_day' => true,
            'working_hours' => [
                'start' => $schedule['start']->format('H:i'),
                'end' => $schedule['end']->format('H:i'),
            ],
            'duration_minutes' => $duration,
            'buffer_minutes' => $buffer,
            'available_slots' => count(array_filter($slots, fn (array $slot): bool => $slot['available'] === true)),
            'slots' => $slots,
        ];
    }

    public function assertBookable(
        DoctorProfile $profile,
        ?Clinic $clinic,
        CarbonInterface $scheduledAt,
        ?int $ignoreAppointmentId = null,
    ): void {
        $timezone = (string) config('app.timezone', 'UTC');
        $start = CarbonImmutable::parse($scheduledAt->toIso8601String())->setTimezone($timezone);
        $preferences = $this->preferences($profile);
        $schedule = $this->scheduleFor($preferences, $start);

        if ($schedule === null || $start->lessThan($schedule['start']) || $start->greaterThanOrEqualTo($schedule['end'])) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['La hora seleccionada está fuera del horario de atención del médico.'],
            ]);
        }

        $duration = (int) $preferences['appointment_duration_minutes'];
        $buffer = (int) $preferences['buffer_minutes'];
        $end = $start->addMinutes($duration);

        if ($clinic !== null && $this->scheduleBlocksFor($profile->user_id, $clinic)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->exists()) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['El horario seleccionado está bloqueado en la agenda.'],
            ]);
        }

        if ($end->greaterThan($schedule['end'])) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['La cita no cabe completa dentro del horario de atención.'],
            ]);
        }

        $blockingAppointment = $this->appointmentsFor($profile->user_id, $clinic)
            ->where('scheduled_at', '>=', $start->subMinutes($duration + $buffer))
            ->where('scheduled_at', '<', $end->addMinutes($buffer))
            ->when($ignoreAppointmentId !== null, fn ($query) => $query->whereKeyNot($ignoreAppointmentId))
            ->orderBy('scheduled_at')
            ->get()
            ->first(fn (Appointment $appointment): bool => $this->overlaps(
                $start,
                $end,
                $appointment->scheduled_at,
                $appointment->scheduled_at?->copy()->addMinutes($duration),
                $buffer,
            ));

        if ($blockingAppointment !== null) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['La hora seleccionada ya está ocupada por otra cita del médico.'],
            ]);
        }
    }

    public function assertAppointmentStatusTransition(AppointmentStatus $current, AppointmentStatus $next): void
    {
        $allowed = match ($current) {
            AppointmentStatus::Pending => [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::Canceled],
            AppointmentStatus::Confirmed => [AppointmentStatus::Confirmed, AppointmentStatus::Waiting, AppointmentStatus::Canceled, AppointmentStatus::NoShow],
            AppointmentStatus::Waiting => [AppointmentStatus::Waiting, AppointmentStatus::InConsultation, AppointmentStatus::Canceled, AppointmentStatus::NoShow],
            AppointmentStatus::InConsultation => [AppointmentStatus::InConsultation, AppointmentStatus::Completed, AppointmentStatus::Canceled],
            AppointmentStatus::Completed, AppointmentStatus::Canceled, AppointmentStatus::NoShow => [$current],
        };

        if (! in_array($next, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede cambiar una cita {$current->label()} a {$next->label()} desde su estado actual."],
            ]);
        }
    }

    public function assertConsultationStatusTransition(ConsultationStatus $current, ConsultationStatus $next): void
    {
        $allowed = match ($current) {
            ConsultationStatus::Draft => [ConsultationStatus::Draft, ConsultationStatus::InProgress, ConsultationStatus::Completed, ConsultationStatus::Canceled],
            ConsultationStatus::InProgress => [ConsultationStatus::InProgress, ConsultationStatus::Completed, ConsultationStatus::Canceled],
            ConsultationStatus::Completed => [ConsultationStatus::Completed, ConsultationStatus::Signed],
            ConsultationStatus::Signed, ConsultationStatus::Canceled => [$current],
        };

        if (! in_array($next, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede cambiar una consulta {$current->label()} a {$next->label()} desde su estado actual."],
            ]);
        }
    }

    /** @return array{start: CarbonImmutable, end: CarbonImmutable}|null */
    private function scheduleFor(array $preferences, CarbonImmutable $date): ?array
    {
        $dayKey = strtolower($date->format('l'));
        $day = $preferences['working_hours'][$dayKey] ?? null;

        if (! is_array($day) || ($day['enabled'] ?? false) !== true) {
            return null;
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $localDate = $date->setTimezone($timezone)->toDateString();
        $startTime = (string) ($day['start'] ?? '');
        $endTime = (string) ($day['end'] ?? '');

        if (! preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $startTime) || ! preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $endTime)) {
            return null;
        }

        $start = CarbonImmutable::parse($localDate.' '.$startTime, $timezone);
        $end = CarbonImmutable::parse($localDate.' '.$endTime, $timezone);

        if (! $end->greaterThan($start)) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    /** @param Collection<int, Appointment> $appointments */
    private function blockingAppointment(Collection $appointments, CarbonImmutable $start, CarbonImmutable $end, int $buffer): ?Appointment
    {
        return $appointments->first(fn (Appointment $appointment): bool => $this->overlaps(
            $start,
            $end,
            $appointment->scheduled_at,
            $appointment->scheduled_at?->copy()->addMinutes(abs($end->diffInMinutes($start))),
            $buffer,
        ));
    }

    private function overlaps(
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?CarbonInterface $otherStart,
        ?CarbonInterface $otherEnd,
        int $buffer,
    ): bool {
        if ($otherStart === null || $otherEnd === null) {
            return false;
        }

        $bufferedEnd = $end->addMinutes($buffer);
        $otherBufferedEnd = CarbonImmutable::parse($otherEnd->toIso8601String())->addMinutes($buffer);
        $otherStart = CarbonImmutable::parse($otherStart->toIso8601String());

        return $start->lessThan($otherBufferedEnd) && $otherStart->lessThan($bufferedEnd);
    }

    /** @return Builder<ClinicScheduleBlock> */
    private function scheduleBlocksFor(int $doctorUserId, Clinic $clinic): Builder
    {
        return ClinicScheduleBlock::query()
            ->where('clinic_id', $clinic->id)
            ->where(function (Builder $query) use ($doctorUserId): void {
                $query->whereNull('doctor_user_id')->orWhere('doctor_user_id', $doctorUserId);
            });
    }

    /** @return Builder<Appointment> */
    private function appointmentsFor(int $doctorUserId, ?Clinic $clinic): Builder
    {
        $query = Appointment::query()
            ->where('doctor_user_id', $doctorUserId)
            ->whereIn('status', self::ACTIVE_APPOINTMENT_STATUSES);

        if ($clinic === null) {
            return $query->whereNull('clinic_id');
        }

        return $query->where('clinic_id', $clinic->id);
    }
}
