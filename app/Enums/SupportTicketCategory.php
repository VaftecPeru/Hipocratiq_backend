<?php

namespace App\Enums;

enum SupportTicketCategory: string
{
    case Technical = 'technical';
    case Billing = 'billing';
    case Functionality = 'functionality';
    case General = 'general';
}
