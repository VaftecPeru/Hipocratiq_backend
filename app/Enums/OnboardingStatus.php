<?php

namespace App\Enums;

enum OnboardingStatus: string
{
    case PhoneVerification = 'phone_verification';
    case PlanSelection = 'plan_selection';
    case Completed = 'completed';
}
