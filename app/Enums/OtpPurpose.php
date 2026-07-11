<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case Registration = 'registration';
    case Login = 'login';
}
