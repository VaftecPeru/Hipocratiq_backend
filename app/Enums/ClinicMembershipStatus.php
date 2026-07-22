<?php

namespace App\Enums;

enum ClinicMembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
