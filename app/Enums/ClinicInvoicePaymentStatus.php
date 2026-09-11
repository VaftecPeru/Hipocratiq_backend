<?php

namespace App\Enums;

enum ClinicInvoicePaymentStatus: string
{
    case Confirmed = 'confirmed';
    case Voided = 'voided';
}
