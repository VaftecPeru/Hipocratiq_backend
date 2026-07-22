<?php

namespace App\Enums;

enum RoleScope: string
{
    case Global = 'global';
    case Organization = 'organization';
}
