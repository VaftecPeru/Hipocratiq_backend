<?php

namespace App\Enums;

enum AdminAssignableRole: string
{
    case SuperAdmin = 'super_admin';
    case ClinicAdmin = 'clinic_admin';
    case Doctor = 'doctor';
    case Patient = 'patient';
}
