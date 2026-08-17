<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Waiting = 'waiting';
    case InConsultation = 'in_consultation';
    case Completed = 'completed';
    case Canceled = 'canceled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Confirmed => 'Confirmada',
            self::Waiting => 'En espera',
            self::InConsultation => 'En atención',
            self::Completed => 'Completada',
            self::Canceled => 'Cancelada',
            self::NoShow => 'No asistió',
        };
    }
}
