<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

final class AdminDashboardCache
{
    public const FreshSeconds = 5;

    public const StaleSeconds = 15;

    /**
     * Dashboard aggregate sources are clinics, users, patients, successful payments, and support tickets.
     * The short flexible-cache TTL bounds writes that do not emit an admin audit event, such as patient registration.
     *
     * @var array<string, list<string>>
     */
    private const INVALIDATING_EVENTS = [
        'billing' => ['manual_payment_recorded', 'manual_payment_refunded'],
        'clinic_team' => ['receptionist_created'],
        'clinics' => ['clinic_created', 'clinic_updated'],
        'memberships' => ['subscription_created', 'subscription_upgraded', 'subscription_canceled'],
        'support' => ['ticket_created', 'ticket_updated'],
        'users' => ['user_created', 'user_updated'],
    ];

    public static function key(): string
    {
        return 'admin-dashboard:read-model:v3';
    }

    public static function invalidateFor(string $module, string $action): void
    {
        if (! self::shouldInvalidateFor($module, $action)) {
            return;
        }

        Cache::forget(self::key());
    }

    public static function shouldInvalidateFor(string $module, string $action): bool
    {
        return in_array($action, self::INVALIDATING_EVENTS[$module] ?? [], true);
    }
}
