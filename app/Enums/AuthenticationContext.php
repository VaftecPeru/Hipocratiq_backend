<?php

namespace App\Enums;

enum AuthenticationContext: string
{
    case Patient = 'patient';
    case Staff = 'staff';
}
