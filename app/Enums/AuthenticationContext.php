<?php

namespace App\Enums;

enum AuthenticationContext: string
{
    case Patient = 'patient';
    case Staff = 'staff';
    case ClinicAdmin = 'clinic_admin';
    case Doctor = 'doctor';
}
