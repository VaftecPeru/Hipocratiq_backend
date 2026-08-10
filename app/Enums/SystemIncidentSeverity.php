<?php

namespace App\Enums;

enum SystemIncidentSeverity: string
{
    case Minor = 'minor';
    case Major = 'major';
    case Critical = 'critical';
}
