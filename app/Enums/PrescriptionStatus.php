<?php

namespace App\Enums;

enum PrescriptionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case RenewalDue = 'renewal_due';
    case Completed = 'completed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Active => 'Activa',
            self::RenewalDue => 'Por renovar',
            self::Completed => 'Completada',
            self::Canceled => 'Cancelada',
        };
    }
}
