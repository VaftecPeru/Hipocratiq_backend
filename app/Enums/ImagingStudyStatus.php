<?php

namespace App\Enums;

enum ImagingStudyStatus: string
{
    case Ordered = 'ordered';
    case Scheduled = 'scheduled';
    case Available = 'available';
    case Reviewed = 'reviewed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Ordered => 'Solicitado',
            self::Scheduled => 'Programado',
            self::Available => 'Disponible',
            self::Reviewed => 'Revisado',
            self::Canceled => 'Cancelado',
        };
    }
}
