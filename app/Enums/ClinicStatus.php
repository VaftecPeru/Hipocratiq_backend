<?php

namespace App\Enums;

enum ClinicStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
