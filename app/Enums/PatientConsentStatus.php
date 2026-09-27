<?php

namespace App\Enums;

enum PatientConsentStatus: string
{
    case Granted = 'granted';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
