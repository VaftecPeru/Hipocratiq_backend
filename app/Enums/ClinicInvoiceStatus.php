<?php

namespace App\Enums;

enum ClinicInvoiceStatus: string
{
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Voided = 'voided';
}
