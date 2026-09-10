<?php

namespace App\Enums;

enum AppointmentType: string
{
    case Consultation = 'consultation';
    case Control = 'control';

    public function label(): string
    {
        return match ($this) {
            self::Consultation => 'Consulta',
            self::Control => 'Control',
        };
    }
}
