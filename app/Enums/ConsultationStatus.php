<?php

namespace App\Enums;

enum ConsultationStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Signed = 'signed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::InProgress => 'En curso',
            self::Completed => 'Completada',
            self::Signed => 'Firmada',
            self::Canceled => 'Cancelada',
        };
    }
}
