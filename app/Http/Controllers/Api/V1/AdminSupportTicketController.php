<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Enums\RoleKey;
use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminSupportTicketCommentRequest;
use App\Http\Requests\Api\V1\AdminSupportTicketIndexRequest;
use App\Http\Requests\Api\V1\AdminSupportTicketStoreRequest;
use App\Http\Requests\Api\V1\AdminSupportTicketUpdateRequest;
use App\Models\Clinic;
use App\Models\SupportTicket;
use App\Models\SupportTicketActivity;
use App\Models\User;
use App\Notifications\SupportTicketCreatedNotification;
use App\Services\Support\SupportTicketWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class AdminSupportTicketController extends Controller
{
    public function index(AdminSupportTicketIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $tickets = $this->ticketQuery()
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('ticket_id', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%")
                        ->orWhere('contact_email', 'like', "%{$search}%");
                });
            })
            ->when($filters['clinic_id'] ?? null, fn (Builder $query, int $clinicId) => $query->where('clinic_id', $clinicId))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['priority'] ?? null, fn (Builder $query, string $priority) => $query->where('priority', $priority))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['country_code'] ?? null, fn (Builder $query, string $countryCode) => $query->whereHas('clinic', fn (Builder $query) => $query->where('country_code', $countryCode)))
            ->when($filters['assigned_to_user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('assigned_to_user_id', $userId))
            ->when($filters['overdue'] ?? false, fn (Builder $query) => $this->applyOverdue($query))
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'data' => $tickets->getCollection()->map(fn (SupportTicket $ticket): array => $this->serializeTicket($ticket)),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'from' => $tickets->firstItem(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'to' => $tickets->lastItem(),
                'total' => $tickets->total(),
            ],
            'summary' => $this->summary(),
            'filter_options' => $this->filterOptions(),
        ]);
    }

    public function show(SupportTicket $supportTicket): JsonResponse
    {
        $supportTicket->load([
            'clinic:id,name,country_code',
            'requestedBy:id,name,email',
            'assignedTo:id,name,email',
            'activities.actor:id,name,email',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializeTicket($supportTicket, includeActivities: true),
        ]);
    }

    public function store(
        AdminSupportTicketStoreRequest $request,
        SupportTicketWorkflow $workflow,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $user = $this->authenticatedUser($request->user());
        $data = $request->validated();

        $ticket = DB::transaction(function () use ($data, $user, $workflow): SupportTicket {
            $now = now();
            $ticket = SupportTicket::create([
                'clinic_id' => $data['clinic_id'],
                'requested_by_user_id' => $user->getKey(),
                'assigned_to_user_id' => ($data['assign_to_self'] ?? false) ? $user->getKey() : null,
                'category' => $data['category'],
                'priority' => $data['priority'],
                'status' => SupportTicketStatus::Open->value,
                'subject' => trim($data['subject']),
                'description' => trim($data['description']),
                'contact_email' => mb_strtolower(trim($data['contact_email'])),
                'notify_on_create' => $data['notify_on_create'] ?? true,
                'due_at' => $workflow->dueAt($now, $data['priority']),
                'last_activity_at' => $now,
            ]);
            $ticket->updateQuietly(['ticket_id' => sprintf('TK-%06d', (int) $ticket->getKey())]);
            $workflow->recordCreated($ticket, $user);

            return $ticket;
        });
        $ticket->load(['clinic:id,name,country_code', 'requestedBy:id,name,email', 'assignedTo:id,name,email']);
        $audit->handle(
            $user,
            $request,
            'support',
            'ticket_created',
            SupportTicket::class,
            $ticket->id,
            $ticket->ticket_id,
            $ticket->clinic,
            metadata: ['priority' => $ticket->priority->value, 'category' => $ticket->category->value],
            critical: $ticket->priority === SupportTicketPriority::Urgent,
        );

        if ($ticket->notify_on_create) {
            Notification::route('mail', $ticket->contact_email)
                ->notify((new SupportTicketCreatedNotification($ticket))->afterCommit());
        }

        return response()->json([
            'success' => true,
            'data' => $this->serializeTicket($ticket),
        ], 201);
    }

    public function update(
        AdminSupportTicketUpdateRequest $request,
        SupportTicket $supportTicket,
        SupportTicketWorkflow $workflow,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $user = $this->authenticatedUser($request->user());
        $data = $request->validated();

        if (array_key_exists('assigned_to_user_id', $data) && $data['assigned_to_user_id'] !== null) {
            $this->assertValidAgent((int) $data['assigned_to_user_id']);
        }

        $before = [
            'status' => $supportTicket->status->value,
            'priority' => $supportTicket->priority->value,
            'assigned_to_user_id' => $supportTicket->assigned_to_user_id,
        ];
        $ticket = $workflow->update($supportTicket, $user, $data);
        $ticket->load(['clinic:id,name,country_code', 'requestedBy:id,name,email', 'assignedTo:id,name,email']);
        $audit->handle(
            $user,
            $request,
            'support',
            'ticket_updated',
            SupportTicket::class,
            $ticket->id,
            $ticket->ticket_id,
            $ticket->clinic,
            metadata: ['before' => $before, 'after' => $data],
            critical: ($data['priority'] ?? null) === SupportTicketPriority::Urgent->value
                || ($data['status'] ?? null) === SupportTicketStatus::Closed->value,
        );

        return response()->json([
            'success' => true,
            'data' => $this->serializeTicket($ticket),
        ]);
    }

    public function comment(
        AdminSupportTicketCommentRequest $request,
        SupportTicket $supportTicket,
        SupportTicketWorkflow $workflow,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $user = $this->authenticatedUser($request->user());
        $data = $request->validated();
        $activity = $workflow->comment(
            $supportTicket,
            $user,
            $data['body'],
            $data['is_internal'] ?? true,
        );
        $activity->load('actor:id,name,email');
        $supportTicket->loadMissing('clinic:id,name,country_code');
        $audit->handle(
            $user,
            $request,
            'support',
            'ticket_commented',
            SupportTicket::class,
            $supportTicket->id,
            $supportTicket->ticket_id,
            $supportTicket->clinic,
            metadata: ['is_internal' => $activity->is_internal],
        );

        return response()->json([
            'success' => true,
            'data' => $this->serializeActivity($activity),
        ], 201);
    }

    private function ticketQuery(): Builder
    {
        return SupportTicket::query()->with([
            'clinic:id,name,country_code',
            'requestedBy:id,name,email',
            'assignedTo:id,name,email',
        ]);
    }

    private function applyOverdue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', [SupportTicketStatus::Resolved->value, SupportTicketStatus::Closed->value]);
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $statusCounts = SupportTicket::query()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $priorityCounts = SupportTicket::query()->selectRaw('priority, COUNT(*) as aggregate')->groupBy('priority')->pluck('aggregate', 'priority');
        $categoryCounts = SupportTicket::query()->selectRaw('category, COUNT(*) as aggregate')->groupBy('category')->pluck('aggregate', 'category');
        $total = (int) $statusCounts->sum();
        $completed = SupportTicket::query()
            ->whereIn('status', [SupportTicketStatus::Resolved->value, SupportTicketStatus::Closed->value])
            ->whereNotNull('resolved_at')
            ->whereNotNull('due_at');
        $completedCount = (clone $completed)->count();
        $withinSla = (clone $completed)->whereColumn('resolved_at', '<=', 'due_at')->count();

        return [
            'totals' => [
                'tickets' => $total,
                'open' => (int) ($statusCounts[SupportTicketStatus::Open->value] ?? 0),
                'in_progress' => (int) ($statusCounts[SupportTicketStatus::InProgress->value] ?? 0),
                'waiting_on_customer' => (int) ($statusCounts[SupportTicketStatus::WaitingOnCustomer->value] ?? 0),
                'resolved_today' => SupportTicket::query()->whereDate('resolved_at', today())->count(),
                'overdue' => $this->applyOverdue(SupportTicket::query())->count(),
                'unassigned' => SupportTicket::query()->whereNull('assigned_to_user_id')->whereNotIn('status', [SupportTicketStatus::Resolved->value, SupportTicketStatus::Closed->value])->count(),
            ],
            'sla' => [
                'compliance_percentage' => $completedCount === 0 ? 100 : round(($withinSla / $completedCount) * 100, 1),
                'target_percentage' => 90,
                'evaluated_tickets' => $completedCount,
            ],
            'by_priority' => collect(SupportTicketPriority::cases())->map(fn (SupportTicketPriority $priority): array => $this->distributionItem($priority->value, (int) ($priorityCounts[$priority->value] ?? 0), $total))->values(),
            'by_category' => collect(SupportTicketCategory::cases())->map(fn (SupportTicketCategory $category): array => $this->distributionItem($category->value, (int) ($categoryCounts[$category->value] ?? 0), $total))->values(),
            'by_country' => $this->countrySummary(),
            'agents' => $this->agentSummary(),
            'escalations' => $this->escalations(),
            'recent_activity' => $this->recentActivity(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{key: string, count: int, percentage: float} */
    private function distributionItem(string $key, int $count, int $total): array
    {
        return [
            'key' => $key,
            'count' => $count,
            'percentage' => $total === 0 ? 0 : round(($count / $total) * 100, 1),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function countrySummary(): array
    {
        $rows = SupportTicket::query()
            ->join('clinics', 'clinics.id', '=', 'support_tickets.clinic_id')
            ->selectRaw("clinics.country_code, COUNT(*) as total, SUM(CASE WHEN support_tickets.status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) as open_count, SUM(CASE WHEN support_tickets.due_at < CURRENT_TIMESTAMP AND support_tickets.status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) as overdue_count")
            ->groupBy('clinics.country_code')
            ->orderBy('clinics.country_code')
            ->get();

        return $rows->map(fn (object $row): array => [
            'country_code' => $row->country_code,
            'total' => (int) $row->total,
            'open' => (int) $row->open_count,
            'overdue' => (int) $row->overdue_count,
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function agentSummary(): array
    {
        return $this->agentQuery()
            ->withCount([
                'assignedSupportTickets as assigned_tickets_count',
                'assignedSupportTickets as active_tickets_count' => fn (Builder $query) => $query->whereNotIn('status', [SupportTicketStatus::Resolved->value, SupportTicketStatus::Closed->value]),
                'assignedSupportTickets as resolved_tickets_count' => fn (Builder $query) => $query->whereIn('status', [SupportTicketStatus::Resolved->value, SupportTicketStatus::Closed->value]),
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'assigned_tickets' => $user->assigned_tickets_count,
                'active_tickets' => $user->active_tickets_count,
                'resolved_tickets' => $user->resolved_tickets_count,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function escalations(): array
    {
        return $this->applyOverdue($this->ticketQuery())
            ->orderBy('due_at')
            ->limit(10)
            ->get()
            ->map(fn (SupportTicket $ticket): array => $this->serializeTicket($ticket))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function recentActivity(): array
    {
        return SupportTicketActivity::query()
            ->with(['ticket:id,ticket_id,clinic_id,subject', 'ticket.clinic:id,name,country_code', 'actor:id,name,email'])
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (SupportTicketActivity $activity): array => $this->serializeActivity($activity))
            ->all();
    }

    /** @return array<string, mixed> */
    private function filterOptions(): array
    {
        return [
            'clinics' => Clinic::query()->where('status', 'active')->orderBy('country_code')->orderBy('name')->get(['id', 'name', 'country_code']),
            'agents' => $this->agentQuery()->orderBy('name')->get(['id', 'name', 'email']),
            'countries' => Clinic::query()->distinct()->orderBy('country_code')->pluck('country_code')->values(),
            'categories' => array_map(fn (SupportTicketCategory $category): string => $category->value, SupportTicketCategory::cases()),
            'priorities' => array_map(fn (SupportTicketPriority $priority): string => $priority->value, SupportTicketPriority::cases()),
            'statuses' => array_map(fn (SupportTicketStatus $status): string => $status->value, SupportTicketStatus::cases()),
        ];
    }

    private function agentQuery(): Builder
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('roles', fn (Builder $query) => $query->where('key', RoleKey::SuperAdmin->value));
    }

    private function assertValidAgent(int $userId): void
    {
        if (! $this->agentQuery()->whereKey($userId)->exists()) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => ['El responsable debe ser un Superadmin activo.'],
            ]);
        }
    }

    private function authenticatedUser(mixed $user): User
    {
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function serializeTicket(SupportTicket $ticket, bool $includeActivities = false): array
    {
        $isFinal = in_array($ticket->status, [SupportTicketStatus::Resolved, SupportTicketStatus::Closed], true);
        $data = [
            'id' => $ticket->id,
            'ticket_id' => $ticket->ticket_id,
            'clinic' => $ticket->clinic?->only(['id', 'name', 'country_code']),
            'category' => $ticket->category->value,
            'priority' => $ticket->priority->value,
            'status' => $ticket->status->value,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'contact_email' => $ticket->contact_email,
            'notify_on_create' => $ticket->notify_on_create,
            'requested_by' => $ticket->requestedBy?->only(['id', 'name', 'email']),
            'assigned_to' => $ticket->assignedTo?->only(['id', 'name', 'email']),
            'due_at' => $ticket->due_at?->toIso8601String(),
            'first_responded_at' => $ticket->first_responded_at?->toIso8601String(),
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'last_activity_at' => $ticket->last_activity_at?->toIso8601String(),
            'is_overdue' => ! $isFinal && $ticket->due_at?->isPast() === true,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
        ];

        if ($includeActivities) {
            $data['activities'] = $ticket->activities->map(fn (SupportTicketActivity $activity): array => $this->serializeActivity($activity))->values();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function serializeActivity(SupportTicketActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'ticket_id' => $activity->ticket?->ticket_id ?? $activity->support_ticket_id,
            'ticket_subject' => $activity->ticket?->subject,
            'clinic' => $activity->ticket?->clinic?->only(['id', 'name', 'country_code']),
            'actor' => $activity->actor?->only(['id', 'name', 'email']),
            'type' => $activity->type->value,
            'body' => $activity->body,
            'from_value' => $activity->from_value,
            'to_value' => $activity->to_value,
            'is_internal' => $activity->is_internal,
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
    }
}
