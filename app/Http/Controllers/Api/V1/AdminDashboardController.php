<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClinicStatus;
use App\Enums\SupportTicketStatus;
use App\Enums\SystemIncidentSeverity;
use App\Enums\SystemIncidentStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditEvent;
use App\Models\AuthenticationEvent;
use App\Models\AuthenticationSession;
use App\Models\Clinic;
use App\Models\PatientProfile;
use App\Models\SubscriptionPayment;
use App\Models\SupportTicket;
use App\Models\SystemIncident;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AdminDashboardController extends Controller
{
    public function show(): JsonResponse
    {
        $now = CarbonImmutable::now();
        $monthStart = $now->startOfMonth();
        $previousMonthStart = $monthStart->subMonth();
        $activeStatuses = [
            SupportTicketStatus::Open->value,
            SupportTicketStatus::InProgress->value,
            SupportTicketStatus::WaitingOnCustomer->value,
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'generated_at' => $now->toIso8601String(),
                'kpis' => [
                    'active_clinics' => Clinic::query()->where('status', ClinicStatus::Active->value)->count(),
                    'clinics_added_this_month' => Clinic::query()->where('created_at', '>=', $monthStart)->count(),
                    'active_users' => User::query()->where('status', UserStatus::Active->value)->count(),
                    'users_added_this_month' => User::query()->where('created_at', '>=', $monthStart)->count(),
                    'active_patients' => PatientProfile::query()->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active->value))->count(),
                    'monthly_revenue_cents' => $this->payments()->where('paid_at', '>=', $monthStart)->sum('amount_cents'),
                    'previous_month_revenue_cents' => $this->payments()->whereBetween('paid_at', [$previousMonthStart, $monthStart])->sum('amount_cents'),
                    'open_support_tickets' => SupportTicket::query()->whereIn('status', $activeStatuses)->count(),
                    'overdue_support_tickets' => SupportTicket::query()->whereIn('status', $activeStatuses)->where('due_at', '<', $now)->count(),
                ],
                'revenue' => $this->revenueSeries($now),
                'countries' => $this->countryDistribution(),
                'recent_clinics' => Clinic::query()
                    ->where('status', ClinicStatus::Active->value)
                    ->latest()
                    ->limit(5)
                    ->get(['id', 'name', 'country_code', 'address', 'created_at']),
                'recent_activity' => AdminAuditEvent::query()
                    ->with('actor:id,name')
                    ->latest('occurred_at')
                    ->limit(5)
                    ->get()
                    ->map(fn (AdminAuditEvent $event): array => [
                        'id' => $event->id,
                        'module' => $event->module,
                        'action' => $event->action,
                        'resource_label' => $event->resource_label,
                        'actor_name' => $event->actor?->name,
                        'country_code' => $event->country_code,
                        'critical' => $event->is_critical,
                        'occurred_at' => $event->occurred_at?->toIso8601String(),
                    ]),
                'system' => $this->systemSnapshot(),
            ],
        ]);
    }

    public function monitoring(): JsonResponse
    {
        $now = CarbonImmutable::now();
        $services = $this->systemSnapshot();
        $activeTicketStatuses = [
            SupportTicketStatus::Open->value,
            SupportTicketStatus::InProgress->value,
            SupportTicketStatus::WaitingOnCustomer->value,
        ];
        $countries = $this->countryDistribution();
        $criticalEvents = AdminAuditEvent::query()
            ->where('is_critical', true)
            ->latest('occurred_at')
            ->limit(6)
            ->get(['id', 'module', 'action', 'resource_label', 'country_code', 'occurred_at']);
        $incidents = SystemIncident::query()
            ->with('creator:id,name')
            ->latest('started_at')
            ->limit(20)
            ->get();
        $hasImpactingIncident = SystemIncident::query()
            ->where('status', '!=', SystemIncidentStatus::Resolved->value)
            ->whereIn('severity', [SystemIncidentSeverity::Major->value, SystemIncidentSeverity::Critical->value])
            ->exists();

        return response()->json([
            'success' => true,
            'data' => [
                'generated_at' => $now->toIso8601String(),
                'overall_status' => collect($services)->contains('status', 'unavailable') || $hasImpactingIncident ? 'degraded' : 'operational',
                'metrics' => [
                    'active_sessions' => AuthenticationSession::query()->whereNull('revoked_at')->where('expires_at', '>', $now)->count(),
                    'pending_jobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
                    'failed_jobs_24h' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', $now->subDay())->count() : 0,
                    'audit_events_24h' => AdminAuditEvent::query()->where('occurred_at', '>=', $now->subDay())->count(),
                    'critical_events_24h' => AdminAuditEvent::query()->where('is_critical', true)->where('occurred_at', '>=', $now->subDay())->count(),
                    'authentication_failures_24h' => AuthenticationEvent::query()->where('outcome', 'failure')->where('occurred_at', '>=', $now->subDay())->count(),
                    'open_support_tickets' => SupportTicket::query()->whereIn('status', $activeTicketStatuses)->count(),
                    'overdue_support_tickets' => SupportTicket::query()->whereIn('status', $activeTicketStatuses)->where('due_at', '<', $now)->count(),
                    'open_incidents' => SystemIncident::query()->where('status', '!=', SystemIncidentStatus::Resolved->value)->count(),
                ],
                'countries' => $countries,
                'services' => $services,
                'operational_signals' => $criticalEvents->map(fn (AdminAuditEvent $event): array => [
                    'id' => $event->id,
                    'module' => $event->module,
                    'action' => $event->action,
                    'resource_label' => $event->resource_label,
                    'country_code' => $event->country_code,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                ]),
                'incidents' => $incidents->map(fn (SystemIncident $incident): array => [
                    'id' => $incident->id,
                    'title' => $incident->title,
                    'description' => $incident->description,
                    'severity' => $incident->severity->value,
                    'status' => $incident->status->value,
                    'service_key' => $incident->service_key,
                    'country_code' => $incident->country_code,
                    'created_by' => $incident->creator?->name,
                    'started_at' => $incident->started_at?->toIso8601String(),
                    'resolved_at' => $incident->resolved_at?->toIso8601String(),
                ])->values(),
                'event_volume' => [
                    'administrative' => AdminAuditEvent::query()->where('occurred_at', '>=', $now->subDay())->count(),
                    'authentication' => AuthenticationEvent::query()->where('occurred_at', '>=', $now->subDay())->count(),
                    'critical' => AdminAuditEvent::query()->where('is_critical', true)->where('occurred_at', '>=', $now->subDay())->count(),
                    'failed_authentication' => AuthenticationEvent::query()->where('outcome', 'failure')->where('occurred_at', '>=', $now->subDay())->count(),
                ],
            ],
        ]);
    }

    private function payments()
    {
        return SubscriptionPayment::query()->where('status', 'succeeded')->whereNotNull('paid_at');
    }

    /** @return list<array{month: string, amount_cents: int}> */
    private function revenueSeries(CarbonImmutable $now): array
    {
        $start = $now->startOfMonth()->subMonths(11);
        $totals = $this->payments()
            ->where('paid_at', '>=', $start)
            ->get(['amount_cents', 'paid_at'])
            ->groupBy(fn (SubscriptionPayment $payment): string => $payment->paid_at->format('Y-m'))
            ->map(fn ($payments): int => (int) $payments->sum('amount_cents'));

        return collect(range(0, 11))->map(function (int $offset) use ($start, $totals): array {
            $month = $start->addMonths($offset);

            return [
                'month' => $month->format('Y-m'),
                'amount_cents' => $totals->get($month->format('Y-m'), 0),
            ];
        })->all();
    }

    /** @return list<array{country_code: string, clinics: int, users: int}> */
    private function countryDistribution(): array
    {
        $clinics = Clinic::query()
            ->where('status', ClinicStatus::Active->value)
            ->selectRaw('country_code, COUNT(*) as aggregate')
            ->groupBy('country_code')
            ->pluck('aggregate', 'country_code');
        $users = User::query()
            ->where('status', UserStatus::Active->value)
            ->selectRaw('country_code, COUNT(*) as aggregate')
            ->groupBy('country_code')
            ->pluck('aggregate', 'country_code');

        return $clinics->keys()
            ->merge($users->keys())
            ->unique()
            ->sort()
            ->map(fn (string $countryCode): array => [
                'country_code' => $countryCode,
                'clinics' => (int) $clinics->get($countryCode, 0),
                'users' => (int) $users->get($countryCode, 0),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{key: string, label: string, status: string, latency_ms: int, detail: string}> */
    private function systemSnapshot(): array
    {
        $services = [
            $this->probe('api', 'Aplicación API', fn (): string => config('app.env').' · '.config('app.name')),
            $this->probe('database', 'Base de datos', function (): string {
                DB::select('SELECT 1');

                return (string) config('database.default');
            }),
            $this->probe('cache', 'Caché', function (): string {
                $key = 'admin-monitoring:'.str()->uuid();
                Cache::put($key, 'ok', 10);
                $value = Cache::get($key);
                Cache::forget($key);

                if ($value !== 'ok') {
                    throw new \RuntimeException('Cache read check failed.');
                }

                return (string) config('cache.default');
            }),
            $this->probe('queue', 'Cola de trabajos', function (): string {
                $pending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
                $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : 0;

                return "{$pending} pendientes · {$failed} fallidos (24h)";
            }),
            $this->probe('storage', 'Almacenamiento local', function (): string {
                $path = storage_path();
                $total = disk_total_space($path);
                $free = disk_free_space($path);

                if ($total === false || $free === false || $total <= 0) {
                    throw new \RuntimeException('Storage capacity unavailable.');
                }

                return round(($free / $total) * 100, 1).'% libre';
            }),
        ];

        $failedJobs = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count()
            : 0;
        if ($failedJobs > 0) {
            foreach ($services as &$service) {
                if ($service['key'] === 'queue' && $service['status'] === 'operational') {
                    $service['status'] = 'degraded';
                }
            }
            unset($service);
        }

        return $services;
    }

    /** @return array{key: string, label: string, status: string, latency_ms: int, detail: string} */
    private function probe(string $key, string $label, Closure $check): array
    {
        $startedAt = hrtime(true);

        try {
            $detail = $check();
            $status = 'operational';
        } catch (Throwable) {
            $detail = 'No disponible';
            $status = 'unavailable';
        }

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'latency_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
            'detail' => $detail,
        ];
    }
}
