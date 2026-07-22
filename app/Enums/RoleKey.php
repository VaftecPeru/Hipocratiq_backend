<?php

namespace App\Enums;

enum RoleKey: string
{
    case SuperAdmin = 'super_admin';
    case ClinicAdmin = 'clinic_admin';
    case Doctor = 'doctor';
}
