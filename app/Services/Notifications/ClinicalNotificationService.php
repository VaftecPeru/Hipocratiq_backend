<?php

namespace App\Services\Notifications;

use App\Enums\ClinicMembershipStatus;
use App\Enums\ClinicStatus;
use App\Enums\RoleKey;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Clinic;
use App\Models\ClinicalNotification;
use App\Models\ClinicMembership;
use App\Models\Role;

final class ClinicalNotificationService
{
    /**
     * @param  iterable<Clinic>  $clinics
     * @param  array<string, mixed>  $policy
     * @return array{candidate_count: int, created_count: int, deduplicated_count: int, roles: list<string>}
     */
    public function createForEmergency(
        iterable $clinics,
        array $policy,
        string $eventKey,
        string $sourceType,
        string $sourceId,
        ?string $note = null,
    ): array {
        $roleKeys = $this->roleKeys($policy);
        $clinicIds = collect($clinics)
            ->map(fn (Clinic $clinic): int => (int) $clinic->getKey())
            ->filter()
            ->unique()
            ->values();

        if (! (bool) ($policy['enabled'] ?? false) || $clinicIds->isEmpty()) {
            return $this->emptyStats($roleKeys);
        }

        $roleIds = Role::query()->whereIn('key', $roleKeys)->pluck('id');

        if ($roleIds->isEmpty()) {
            return $this->emptyStats($roleKeys);
        }

        $memberships = ClinicMembership::query()
            ->whereIn('clinic_id', $clinicIds)
            ->where('status', ClinicMembershipStatus::Active->value)
            ->whereIn('role_id', $roleIds)
            ->whereHas('user', function ($query): void {
                $query->where('status', UserStatus::Active->value);
            })
            ->whereHas('clinic', function ($query): void {
                $query
                    ->where('status', ClinicStatus::Active->value)
                    ->whereHas('subscriptions', function ($subscriptionQuery): void {
                        $subscriptionQuery
                            ->where('status', SubscriptionStatus::Active->value)
                            ->where(function ($dateQuery): void {
                                $dateQuery->whereNull('ends_at')->orWhere('ends_at', '>', now());
                            });
                    });
            })
            ->with(['clinic:id,name,country_code', 'role:id,key', 'user:id,name'])
            ->get();

        $occurredAt = now();
        $rows = $memberships
            ->map(function (ClinicMembership $membership) use ($eventKey, $sourceType, $sourceId, $note, $occurredAt, $policy): array {
                $roleKey = (string) $membership->role->key;
                $isPublication = $eventKey === 'emergency_published';

                return [
                    'clinic_id' => $membership->clinic->getKey(),
                    'user_id' => $membership->user->getKey(),
                    'type' => 'emergency',
                    'event_key' => $eventKey,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'title_key' => $isPublication
                        ? 'clinicalNotifications.emergency.publishedTitle'
                        : 'clinicalNotifications.emergency.activatedTitle',
                    'message_key' => $isPublication
                        ? 'clinicalNotifications.emergency.publishedMessage'
                        : 'clinicalNotifications.emergency.activatedMessage',
                    'severity' => 'critical',
                    'href' => $this->hrefFor($roleKey),
                    'payload' => json_encode([
                        'clinic_name' => $membership->clinic->name,
                        'policy_label' => (string) ($policy['label'] ?? 'Emergencia'),
                        'role_key' => $roleKey,
                        'note' => $note,
                    ], JSON_THROW_ON_ERROR),
                    'occurred_at' => $occurredAt,
                    'created_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ];
            })
            ->values()
            ->all();

        $candidateCount = count($rows);
        $createdCount = $candidateCount === 0
            ? 0
            : ClinicalNotification::query()->insertOrIgnore($rows);

        return [
            'candidate_count' => $candidateCount,
            'created_count' => $createdCount,
            'deduplicated_count' => $candidateCount - $createdCount,
            'roles' => $roleKeys,
        ];
    }

    /** @param list<string> $roleKeys */
    private function emptyStats(array $roleKeys): array
    {
        return [
            'candidate_count' => 0,
            'created_count' => 0,
            'deduplicated_count' => 0,
            'roles' => $roleKeys,
        ];
    }

    /** @return list<string> */
    private function roleKeys(array $policy): array
    {
        return match ($policy['visibility'] ?? null) {
            'admins_only' => [RoleKey::ClinicAdmin->value],
            'doctors_admins' => [RoleKey::ClinicAdmin->value, RoleKey::Doctor->value],
            default => [
                RoleKey::ClinicAdmin->value,
                RoleKey::Doctor->value,
                RoleKey::Receptionist->value,
            ],
        };
    }

    private function hrefFor(string $roleKey): string
    {
        return match ($roleKey) {
            RoleKey::Doctor->value => '/doctor/dashboard',
            RoleKey::Receptionist->value => '/clinic/dashboard',
            default => '/clinic/configuracion/emergencia',
        };
    }
}
