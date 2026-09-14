<?php

namespace App\Enums;

enum OtpDeliveryChannel: string
{
    case Email = 'email';
    case Phone = 'phone';
}
