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
        'memberships' => ['plan_created', 'plan_updated', 'subscription_created', 'subscription_upgraded', 'subscription_canceled'],
        'specialties' => ['specialty_created', 'specialty_updated'],
        'support' => ['ticket_created', 'ticket_updated'],
        'users' => ['user_created', 'user_updated'],
    ];

    public static function key(): string
    {
        return 'admin-dashboard:read-model:v4';
    }

    public static function invalidateFor(string $module, string $action): void
    {
        if (! self::shouldInvalidateFor($module, $action)) {
            return;
        }

        Cache::forget(self::key());

        if ($module === 'billing') {
            self::bumpVersion('billing');
        }

        if ($module === 'memberships') {
            self::bumpVersion('memberships');
        }
    }

    /**
     * Build a user- and filter-scoped key for admin read models.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function resourceKey(string $resource, int|string|null $userId, array $filters = []): string
    {
        $version = (int) Cache::get("admin-read-model:{$resource}:version", 1);
        $filterHash = hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));

        return "admin-read-model:{$resource}:v{$version}:user:".($userId ?? 'anonymous').":{$filterHash}";
    }

    public static function remember(string $key, callable $callback): mixed
    {
        if (app()->environment('testing')) {
            return $callback();
        }

        return Cache::remember($key, now()->addSeconds(self::FreshSeconds), $callback);
    }

    private static function bumpVersion(string $resource): void
    {
        $key = "admin-read-model:{$resource}:version";
        Cache::add($key, 1, now()->addDay());
        Cache::increment($key);
    }

    public static function shouldInvalidateFor(string $module, string $action): bool
    {
        return in_array($action, self::INVALIDATING_EVENTS[$module] ?? [], true);
    }
}
