<?php

namespace App\Enums;

enum TeleconsultationStatus: string
{
    case Scheduled = 'scheduled';
    case Waiting = 'waiting';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Programada',
            self::Waiting => 'En sala de espera',
            self::InProgress => 'En curso',
            self::Completed => 'Finalizada',
            self::Canceled => 'Cancelada',
        };
    }
}
