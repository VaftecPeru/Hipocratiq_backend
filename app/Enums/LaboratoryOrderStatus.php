<?php

namespace App\Enums;

enum LaboratoryOrderStatus: string
{
    case Requested = 'requested';
    case InProgress = 'in_progress';
    case Ready = 'ready';
    case Reviewed = 'reviewed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Solicitado',
            self::InProgress => 'En proceso',
            self::Ready => 'Listo para revisar',
            self::Reviewed => 'Revisado',
            self::Canceled => 'Cancelado',
        };
    }
}
