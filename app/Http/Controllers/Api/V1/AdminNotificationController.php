<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SupportTicketStatus;
use App\Enums\SystemIncidentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminNotificationReadRequest;
use App\Models\AdminAuditEvent;
use App\Models\AdminNotificationRead;
use App\Models\SupportTicket;
use App\Models\SystemIncident;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AdminNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $this->notifications($request->user());

        return response()->json([
            'success' => true,
            'data' => $notifications,
            'meta' => [
                'total' => $notifications->count(),
                'unread_count' => $notifications->where('read', false)->count(),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function read(AdminNotificationReadRequest $request): JsonResponse
    {
        $validIds = $this->notifications($request->user())->pluck('id');
        $ids = collect($request->validated('notification_ids'))->intersect($validIds)->values();
        $this->markRead($request->user(), $ids);

        return response()->json(['success' => true, 'data' => ['marked_read' => $ids->count()]]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $ids = $this->notifications($request->user())->pluck('id');
        $this->markRead($request->user(), $ids);

        return response()->json(['success' => true, 'data' => ['marked_read' => $ids->count()]]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function notifications(User $user): Collection
    {
        $auditNotifications = AdminAuditEvent::query()
            ->where('is_critical', true)
            ->where('module', '!=', 'monitoring')
            ->latest('occurred_at')
            ->limit(30)
            ->get()
            ->map(fn (AdminAuditEvent $event): array => [
                'id' => "audit:{$event->id}",
                'type' => 'audit',
                'title' => str($event->action)->replace('_', ' ')->title()->toString(),
                'message' => $event->resource_label ?? "Evento crítico en {$event->module}",
                'severity' => 'critical',
                'country_code' => $event->country_code,
                'href' => '/audit',
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                '_timestamp' => $event->occurred_at?->getTimestamp() ?? 0,
            ]);
        $incidentNotifications = SystemIncident::query()
            ->latest('started_at')
            ->limit(30)
            ->get()
            ->map(fn (SystemIncident $incident): array => [
                'id' => "incident:{$incident->id}",
                'type' => 'incident',
                'title' => $incident->title,
                'message' => $incident->status === SystemIncidentStatus::Resolved
                    ? 'Incidente resuelto'
                    : 'Seguimiento operativo: '.str($incident->status->value)->replace('_', ' '),
                'severity' => $incident->severity->value === 'minor' ? 'warning' : 'critical',
                'country_code' => $incident->country_code,
                'href' => '/monitoring',
                'occurred_at' => $incident->started_at?->toIso8601String(),
                '_timestamp' => $incident->started_at?->getTimestamp() ?? 0,
            ]);
        $activeStatuses = [
            SupportTicketStatus::Open->value,
            SupportTicketStatus::InProgress->value,
            SupportTicketStatus::WaitingOnCustomer->value,
        ];
        $supportNotifications = SupportTicket::query()
            ->with('clinic:id,name,country_code')
            ->whereIn('status', $activeStatuses)
            ->where('due_at', '<', now())
            ->oldest('due_at')
            ->limit(30)
            ->get()
            ->map(fn (SupportTicket $ticket): array => [
                'id' => "support:{$ticket->id}",
                'type' => 'support',
                'title' => "Ticket {$ticket->ticket_id} fuera de SLA",
                'message' => $ticket->subject.' · '.($ticket->clinic?->name ?? 'Sin clínica'),
                'severity' => 'warning',
                'country_code' => $ticket->clinic?->country_code,
                'href' => '/support',
                'occurred_at' => $ticket->due_at?->toIso8601String(),
                '_timestamp' => $ticket->due_at?->getTimestamp() ?? 0,
            ]);
        $reads = AdminNotificationRead::query()
            ->where('user_id', $user->getKey())
            ->get(['source_type', 'source_id'])
            ->mapWithKeys(fn (AdminNotificationRead $read): array => ["{$read->source_type}:{$read->source_id}" => true]);

        return $auditNotifications
            ->concat($incidentNotifications)
            ->concat($supportNotifications)
            ->sortByDesc('_timestamp')
            ->take(50)
            ->map(function (array $notification) use ($reads): array {
                $notification['read'] = $reads->has($notification['id']);
                unset($notification['_timestamp']);

                return $notification;
            })
            ->values();
    }

    /** @param Collection<int, string> $ids */
    private function markRead(User $user, Collection $ids): void
    {
        $ids->each(function (string $id) use ($user): void {
            [$sourceType, $sourceId] = explode(':', $id, 2);
            AdminNotificationRead::query()->updateOrCreate(
                ['user_id' => $user->getKey(), 'source_type' => $sourceType, 'source_id' => $sourceId],
                ['read_at' => now()],
            );
        });
    }
}
