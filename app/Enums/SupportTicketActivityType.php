<?php

namespace App\Enums;

enum SupportTicketActivityType: string
{
    case Created = 'created';
    case Comment = 'comment';
    case StatusChanged = 'status_changed';
    case PriorityChanged = 'priority_changed';
    case AssignmentChanged = 'assignment_changed';
}
