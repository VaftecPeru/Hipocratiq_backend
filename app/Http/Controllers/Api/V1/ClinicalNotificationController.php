<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuthenticationContext;
use App\Enums\ClinicMembershipStatus;
use App\Enums\ClinicStatus;
use App\Enums\DoctorPracticeType;
use App\Enums\RoleKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClinicalNotificationReadRequest;
use App\Models\Clinic;
use App\Models\ClinicalNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicalNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $clinic = $user instanceof User ? $this->clinicFor($request, $user) : null;

        if ($clinic === null || ! $user instanceof User) {
            return $this->accessRequiredResponse();
        }

        $query = $this->visibleNotifications($user, $clinic);
        $notifications = (clone $query)->limit(50)->get();

        return response()->json([
            'success' => true,
            'data' => $notifications
                ->map(fn (ClinicalNotification $notification): array => $this->serialize($notification))
                ->values(),
            'meta' => [
                'unread_count' => (clone $query)->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function read(ClinicalNotificationReadRequest $request): JsonResponse
    {
        $user = $request->user();
        $clinic = $user instanceof User ? $this->clinicFor($request, $user) : null;

        if ($clinic === null || ! $user instanceof User) {
            return $this->accessRequiredResponse();
        }

        $ids = collect($request->validated('notification_ids'))
            ->map(fn (string $id): int => (int) str_replace('clinical:', '', $id))
            ->values();
        $markedCount = ClinicalNotification::query()
            ->where('user_id', $user->getKey())
            ->where('clinic_id', $clinic->getKey())
            ->whereIn('id', $ids)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => ['marked_count' => $markedCount],
            'meta' => [
                'unread_count' => $this->unreadCount($user, $clinic),
            ],
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $clinic = $user instanceof User ? $this->clinicFor($request, $user) : null;

        if ($clinic === null || ! $user instanceof User) {
            return $this->accessRequiredResponse();
        }

        $markedCount = ClinicalNotification::query()
            ->where('user_id', $user->getKey())
            ->where('clinic_id', $clinic->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => ['marked_count' => $markedCount],
            'meta' => ['unread_count' => 0],
        ]);
    }

    private function clinicFor(Request $request, User $user): ?Clinic
    {
        $requestClinic = $request->attributes->get('clinic');

        if ($requestClinic instanceof Clinic) {
            return $requestClinic;
        }

        $actorContext = $request->attributes->get('actor_context');
        $roleKey = match ($actorContext) {
            AuthenticationContext::ClinicAdmin->value => RoleKey::ClinicAdmin,
            AuthenticationContext::Receptionist->value => RoleKey::Receptionist,
            default => null,
        };

        if ($roleKey !== null) {
            return $user->clinicMemberships()
                ->where('status', ClinicMembershipStatus::Active->value)
                ->whereHas('role', fn (Builder $query): Builder => $query->where('key', $roleKey->value))
                ->whereHas('clinic', fn (Builder $query): Builder => $query->where('status', ClinicStatus::Active->value))
                ->whereHas('clinic.subscriptions', fn (Builder $query): Builder => $query->currentlyActive())
                ->with('clinic')
                ->orderBy('clinic_id')
                ->first()?->clinic;
        }

        if ($actorContext !== AuthenticationContext::Doctor->value) {
            return null;
        }

        $profile = $user->doctorProfile()->first();

        if ($profile === null || $profile->practice_type !== DoctorPracticeType::Clinic || $profile->clinic_id === null) {
            return null;
        }

        return $user->clinicMemberships()
            ->where('clinic_id', $profile->clinic_id)
            ->where('status', ClinicMembershipStatus::Active->value)
            ->whereHas('role', fn (Builder $query): Builder => $query->where('key', RoleKey::Doctor->value))
            ->whereHas('clinic', fn (Builder $query): Builder => $query->where('status', ClinicStatus::Active->value))
            ->whereHas('clinic.subscriptions', fn (Builder $query): Builder => $query->currentlyActive())
            ->with('clinic')
            ->first()?->clinic;
    }

    /** @return Builder<ClinicalNotification> */
    private function visibleNotifications(User $user, Clinic $clinic): Builder
    {
        return ClinicalNotification::query()
            ->where('user_id', $user->getKey())
            ->where('clinic_id', $clinic->getKey())
            ->with('clinic:id,name,country_code')
            ->latest('occurred_at');
    }

    private function unreadCount(User $user, Clinic $clinic): int
    {
        return ClinicalNotification::query()
            ->where('user_id', $user->getKey())
            ->where('clinic_id', $clinic->getKey())
            ->whereNull('read_at')
            ->count();
    }

    /** @return array<string, mixed> */
    private function serialize(ClinicalNotification $notification): array
    {
        $payload = is_array($notification->payload) ? $notification->payload : [];

        return [
            'id' => 'clinical:'.$notification->getKey(),
            'type' => $notification->type,
            'event_key' => $notification->event_key,
            'title_key' => $notification->title_key,
            'message_key' => $notification->message_key,
            'severity' => $notification->severity,
            'href' => $notification->href,
            'payload' => $payload,
            'clinic' => [
                'id' => $notification->clinic?->getKey(),
                'name' => $notification->clinic?->name,
                'country_code' => $notification->clinic?->country_code,
            ],
            'occurred_at' => $notification->occurred_at?->toIso8601String(),
            'read_at' => $notification->read_at?->toIso8601String(),
            'is_read' => $notification->read_at !== null,
        ];
    }

    private function accessRequiredResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'CLINICAL_NOTIFICATION_ACCESS_REQUIRED',
            'message' => 'Tu cuenta no tiene una membresía clínica activa.',
        ], 403);
    }
}
