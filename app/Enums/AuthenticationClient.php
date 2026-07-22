<?php

namespace App\Enums;

enum AuthenticationClient: string
{
    case Web = 'web';
    case Mobile = 'mobile';
}
