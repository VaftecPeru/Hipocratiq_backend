<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminAuditIndexRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminAuditController extends Controller
{
    public function index(AdminAuditIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = $this->filteredQuery($filters);
        $perPage = $filters['per_page'] ?? 20;
        $page = $filters['page'] ?? 1;
        $total = (clone $query)->count();
        $events = (clone $query)
            ->orderByDesc('occurred_at')
            ->orderByDesc('source_id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (object $event): array => $this->serializeEvent($event));

        return response()->json([
            'success' => true,
            'data' => $events,
            'meta' => [
                'current_page' => $page,
                'from' => $total === 0 ? null : (($page - 1) * $perPage) + 1,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'to' => $total === 0 ? null : min($page * $perPage, $total),
                'total' => $total,
            ],
            'summary' => $this->summary($query),
            'filter_options' => $this->filterOptions(),
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function filteredQuery(array $filters): Builder
    {
        $query = DB::query()->fromSub($this->unifiedEvents(), 'audit_events');

        return $query
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('actor_name', 'like', "%{$search}%")
                        ->orWhere('actor_email', 'like', "%{$search}%")
                        ->orWhere('resource_label', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%");
                });
            })
            ->when($filters['user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('actor_user_id', $userId))
            ->when($filters['module'] ?? null, fn (Builder $query, string $module) => $query->where('module', $module))
            ->when($filters['action'] ?? null, fn (Builder $query, string $action) => $query->where('action', $action))
            ->when($filters['country_code'] ?? null, fn (Builder $query, string $countryCode) => $query->where('country_code', strtoupper($countryCode)))
            ->when(array_key_exists('critical', $filters), fn (Builder $query) => $query->where('is_critical', $filters['critical'] ? 1 : 0))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->where('occurred_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->where('occurred_at', '<=', Carbon::parse($date)->endOfDay()));
    }

    private function unifiedEvents(): Builder
    {
        $adminEvents = DB::table('admin_audit_events')
            ->leftJoin('users as admin_actors', 'admin_actors.id', '=', 'admin_audit_events.actor_user_id')
            ->leftJoin('clinics as admin_clinics', 'admin_clinics.id', '=', 'admin_audit_events.clinic_id')
            ->select([
                DB::raw("'admin' as source"),
                'admin_audit_events.id as source_id',
                'admin_audit_events.actor_user_id',
                'admin_actors.name as actor_name',
                'admin_actors.email as actor_email',
                'admin_audit_events.clinic_id',
                'admin_clinics.name as clinic_name',
                'admin_audit_events.country_code',
                'admin_audit_events.module',
                'admin_audit_events.action',
                DB::raw("'success' as outcome"),
                DB::raw('CASE WHEN admin_audit_events.is_critical THEN 1 ELSE 0 END as is_critical'),
                'admin_audit_events.resource_type',
                'admin_audit_events.resource_id',
                'admin_audit_events.resource_label',
                'admin_audit_events.ip_address',
                DB::raw('CAST(admin_audit_events.metadata AS TEXT) as metadata'),
                'admin_audit_events.occurred_at',
            ]);

        $authenticationEvents = DB::table('authentication_events')
            ->leftJoin('users as auth_actors', 'auth_actors.id', '=', 'authentication_events.user_id')
            ->select([
                DB::raw("'authentication' as source"),
                'authentication_events.id as source_id',
                'authentication_events.user_id as actor_user_id',
                'auth_actors.name as actor_name',
                'auth_actors.email as actor_email',
                DB::raw('NULL as clinic_id'),
                DB::raw('NULL as clinic_name'),
                'auth_actors.country_code',
                DB::raw("'authentication' as module"),
                'authentication_events.event as action',
                'authentication_events.outcome',
                DB::raw("CASE WHEN authentication_events.outcome = 'failure' THEN 1 ELSE 0 END as is_critical"),
                DB::raw("'User' as resource_type"),
                DB::raw('CAST(authentication_events.user_id AS VARCHAR) as resource_id'),
                'auth_actors.name as resource_label',
                'authentication_events.ip_address',
                DB::raw('CAST(authentication_events.metadata AS TEXT) as metadata'),
                'authentication_events.occurred_at',
            ]);

        return $adminEvents->unionAll($authenticationEvents);
    }

    private function summary(Builder $query): array
    {
        $total = (clone $query)->count();
        $modules = (clone $query)
            ->select('module', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('module')
            ->orderByDesc('aggregate')
            ->get();

        return [
            'totals' => [
                'events' => $total,
                'unique_users' => (clone $query)->whereNotNull('actor_user_id')->distinct()->count('actor_user_id'),
                'countries' => (clone $query)->whereNotNull('country_code')->distinct()->count('country_code'),
                'critical' => (clone $query)->where('is_critical', 1)->count(),
            ],
            'top_modules' => $modules->take(6)->map(fn (object $row): array => [
                'module' => $row->module,
                'count' => (int) $row->aggregate,
                'percentage' => $total === 0 ? 0 : round(((int) $row->aggregate / $total) * 100, 1),
            ])->values(),
            'by_country' => (clone $query)
                ->whereNotNull('country_code')
                ->select('country_code', DB::raw('COUNT(*) as aggregate'))
                ->groupBy('country_code')
                ->orderByDesc('aggregate')
                ->get()
                ->map(fn (object $row): array => ['country_code' => $row->country_code, 'count' => (int) $row->aggregate]),
            'critical_events' => (clone $query)
                ->where('is_critical', 1)
                ->orderByDesc('occurred_at')
                ->limit(8)
                ->get()
                ->map(fn (object $event): array => $this->serializeEvent($event)),
            'recent_changes' => (clone $query)
                ->where('source', 'admin')
                ->orderByDesc('occurred_at')
                ->limit(8)
                ->get()
                ->map(fn (object $event): array => $this->serializeEvent($event)),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function filterOptions(): array
    {
        return [
            'modules' => DB::query()->fromSub($this->unifiedEvents(), 'audit_events')->distinct()->orderBy('module')->pluck('module')->values(),
            'actions' => DB::query()->fromSub($this->unifiedEvents(), 'audit_events')->distinct()->orderBy('action')->pluck('action')->values(),
            'countries' => DB::query()->fromSub($this->unifiedEvents(), 'audit_events')->whereNotNull('country_code')->distinct()->orderBy('country_code')->pluck('country_code')->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeEvent(object $event): array
    {
        $metadata = is_string($event->metadata) ? json_decode($event->metadata, true) : $event->metadata;

        return [
            'id' => "{$event->source}:{$event->source_id}",
            'source' => $event->source,
            'actor' => $event->actor_user_id === null ? null : [
                'id' => (int) $event->actor_user_id,
                'name' => $event->actor_name,
                'email' => $event->actor_email,
            ],
            'clinic' => $event->clinic_id === null ? null : [
                'id' => (int) $event->clinic_id,
                'name' => $event->clinic_name,
            ],
            'country_code' => $event->country_code,
            'module' => $event->module,
            'action' => $event->action,
            'outcome' => $event->outcome,
            'is_critical' => (bool) $event->is_critical,
            'resource' => $event->resource_type === null ? null : [
                'type' => $event->resource_type,
                'id' => $event->resource_id,
                'label' => $event->resource_label,
            ],
            'ip_address' => $event->ip_address,
            'metadata' => is_array($metadata) ? $metadata : [],
            'occurred_at' => Carbon::parse($event->occurred_at)->toIso8601String(),
        ];
    }
}
