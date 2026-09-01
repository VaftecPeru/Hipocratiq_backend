<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Enums\ClinicStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminBillingIndexRequest;
use App\Http\Requests\Api\V1\AdminManualPaymentRefundRequest;
use App\Http\Requests\Api\V1\AdminManualPaymentStoreRequest;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\PatientProfile;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminBillingController extends Controller
{
    public function index(AdminBillingIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $payments = $this->filteredPayments($filters)
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => $payments->getCollection()->map(fn (SubscriptionPayment $payment): array => $this->serializePayment($payment))->values()->all(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'from' => $payments->firstItem(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'to' => $payments->lastItem(),
                'total' => $payments->total(),
            ],
            'summary' => $this->summary(),
            'filter_options' => [
                'countries' => Clinic::query()->distinct()->orderBy('country_code')->pluck('country_code')->values(),
                'statuses' => SubscriptionPayment::query()->distinct()->orderBy('status')->pluck('status')->values(),
                'manual_subscriptions' => ClinicSubscription::query()
                    ->with(['clinic:id,name,country_code', 'plan:id,name'])
                    ->where('provider', 'admin_manual')
                    ->where('status', 'active')
                    ->latest('id')
                    ->get()
                    ->map(fn (ClinicSubscription $subscription): array => [
                        'id' => $subscription->id,
                        'clinic' => $subscription->clinic?->name,
                        'country_code' => $subscription->clinic?->country_code,
                        'plan' => $subscription->plan?->name,
                    ])->values()->all(),
            ],
        ]);
    }

    public function storeManualPayment(
        AdminManualPaymentStoreRequest $request,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $data = $request->validated();
        $subscription = ClinicSubscription::query()
            ->with(['clinic', 'plan'])
            ->findOrFail($data['clinic_subscription_id']);

        if ($subscription->provider !== 'admin_manual' || $subscription->status->value !== 'active') {
            throw ValidationException::withMessages([
                'clinic_subscription_id' => ['Solo se pueden conciliar pagos de suscripciones manuales activas.'],
            ]);
        }

        $payment = DB::transaction(function () use ($data): SubscriptionPayment {
            return SubscriptionPayment::query()->create([
                'clinic_subscription_id' => $data['clinic_subscription_id'],
                'idempotency_key' => 'admin_manual:'.hash('sha256', $data['reference']),
                'amount_cents' => (int) round(((float) $data['amount']) * 100),
                'currency' => strtoupper($data['currency']),
                'status' => 'succeeded',
                'provider' => 'admin_manual',
                'provider_reference' => trim($data['reference']),
                'reconciliation_note' => $data['note'] ?? null,
                'paid_at' => $data['paid_at'],
            ]);
        });
        $payment->load(['subscription.clinic', 'subscription.plan']);

        $audit->handle(
            $request->user(),
            $request,
            'billing',
            'manual_payment_recorded',
            SubscriptionPayment::class,
            $payment->id,
            $payment->provider_reference,
            $subscription->clinic,
            metadata: [
                'amount_cents' => $payment->amount_cents,
                'currency' => $payment->currency,
                'subscription_id' => $subscription->id,
            ],
            critical: true,
        );

        return response()->json(['success' => true, 'data' => $this->serializePayment($payment)], 201);
    }

    public function refundManualPayment(
        SubscriptionPayment $payment,
        AdminManualPaymentRefundRequest $request,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        if ($payment->provider !== 'admin_manual' || $payment->status !== 'succeeded') {
            throw ValidationException::withMessages([
                'payment' => ['Solo se pueden reembolsar pagos manuales confirmados.'],
            ]);
        }

        $data = $request->validated();
        $payment->update([
            'status' => 'refunded',
            'refund_reference' => trim($data['reference']),
            'reconciliation_note' => trim(implode("\n\n", array_filter([
                $payment->reconciliation_note,
                'Motivo del reembolso: '.trim($data['reason']),
            ]))),
            'refunded_at' => now(),
        ]);
        $payment->load(['subscription.clinic', 'subscription.plan']);

        $audit->handle(
            $request->user(),
            $request,
            'billing',
            'manual_payment_refunded',
            SubscriptionPayment::class,
            $payment->id,
            $payment->provider_reference,
            $payment->subscription?->clinic,
            metadata: [
                'amount_cents' => $payment->amount_cents,
                'currency' => $payment->currency,
                'refund_reference' => $payment->refund_reference,
                'reason' => $data['reason'],
            ],
            critical: true,
        );

        return response()->json(['success' => true, 'data' => $this->serializePayment($payment)]);
    }

    public function export(
        AdminBillingIndexRequest $request,
        RecordAdminAuditEvent $audit,
    ): StreamedResponse {
        $filters = $request->validated();
        $audit->handle(
            $request->user(),
            $request,
            'billing',
            'billing_exported',
            metadata: ['filters' => $filters],
        );

        return response()->streamDownload(function () use ($filters): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['ID', 'Referencia', 'Clínica', 'País', 'Plan', 'Estado', 'Monto', 'Moneda', 'Proveedor', 'Fecha de pago']);
            $this->filteredPayments($filters)->latest('id')->chunkById(200, function ($payments) use ($output): void {
                foreach ($payments as $payment) {
                    $item = $this->serializePayment($payment);
                    fputcsv($output, [$item['id'], $item['reference'], $item['clinic']['name'], $item['clinic']['country_code'], $item['plan'], $item['status'], $item['amount_cents'] / 100, $item['currency'], $item['provider'], $item['paid_at']]);
                }
            });
            fclose($output);
        }, 'facturacion-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function reportExport(RecordAdminAuditEvent $audit, AdminBillingIndexRequest $request): StreamedResponse
    {
        $audit->handle($request->user(), $request, 'reports', 'executive_report_exported');
        $summary = $this->summary();
        $countries = $this->countrySummary();

        return response()->streamDownload(function () use ($summary, $countries): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Reporte ejecutivo global', now()->toIso8601String()]);
            fputcsv($output, []);
            fputcsv($output, ['Indicador', 'Valor']);
            fputcsv($output, ['Clínicas activas', Clinic::query()->where('status', ClinicStatus::Active->value)->count()]);
            fputcsv($output, ['Usuarios activos', User::query()->where('status', UserStatus::Active->value)->count()]);
            fputcsv($output, ['Pacientes activos', PatientProfile::query()->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active->value))->count()]);
            fputcsv($output, ['Ingresos del mes USD', $summary['monthly_revenue_cents'] / 100]);
            fputcsv($output, []);
            fputcsv($output, ['País', 'Clínicas activas', 'Usuarios activos', 'Ingresos acumulados USD']);
            foreach ($countries as $country) {
                fputcsv($output, [$country['country_code'], $country['clinics'], $country['users'], $country['revenue_cents'] / 100]);
            }
            fclose($output);
        }, 'reporte-ejecutivo-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param array<string, mixed> $filters */
    private function filteredPayments(array $filters): Builder
    {
        return SubscriptionPayment::query()
            ->with(['subscription.clinic:id,name,country_code', 'subscription.plan:id,name'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('provider_reference', 'like', "%{$search}%")
                        ->orWhere('idempotency_key', 'like', "%{$search}%")
                        ->orWhereHas('subscription.clinic', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['country_code'] ?? null, fn (Builder $query, string $country) => $query->whereHas('subscription.clinic', fn (Builder $query) => $query->where('country_code', $country)))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_at', '<=', $date));
    }

    /** @return array<string, mixed> */
    private function serializePayment(SubscriptionPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'reference' => $payment->provider_reference,
            'clinic' => [
                'id' => $payment->subscription?->clinic?->id,
                'name' => $payment->subscription?->clinic?->name ?? 'Clínica eliminada',
                'country_code' => $payment->subscription?->clinic?->country_code,
            ],
            'plan' => $payment->subscription?->plan?->name,
            'status' => $payment->status,
            'amount_cents' => $payment->amount_cents,
            'currency' => $payment->currency,
            'provider' => $payment->provider,
            'reconciliation_note' => $payment->reconciliation_note,
            'refund_reference' => $payment->refund_reference,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'refunded_at' => $payment->refunded_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $now = CarbonImmutable::now();
        $month = $now->startOfMonth();
        $previousMonth = $month->subMonth();
        $succeeded = SubscriptionPayment::query()->where('status', 'succeeded')->whereNotNull('paid_at');

        return [
            'monthly_revenue_cents' => (clone $succeeded)->where('paid_at', '>=', $month)->sum('amount_cents'),
            'previous_month_revenue_cents' => (clone $succeeded)->whereBetween('paid_at', [$previousMonth, $month])->sum('amount_cents'),
            'successful_payments' => SubscriptionPayment::query()->where('status', 'succeeded')->count(),
            'pending_payments' => SubscriptionPayment::query()->where('status', 'pending')->count(),
            'failed_payments' => SubscriptionPayment::query()->where('status', 'failed')->count(),
            'total_revenue_cents' => (clone $succeeded)->sum('amount_cents'),
            'revenue' => $this->revenueSeries($now),
            'countries' => $this->countrySummary(),
        ];
    }

    /** @return list<array{month: string, amount_cents: int}> */
    private function revenueSeries(CarbonImmutable $now): array
    {
        $start = $now->startOfMonth()->subMonths(11);
        $totals = SubscriptionPayment::query()->where('status', 'succeeded')->where('paid_at', '>=', $start)->get(['amount_cents', 'paid_at'])->groupBy(fn (SubscriptionPayment $payment): string => $payment->paid_at->format('Y-m'))->map(fn ($payments): int => (int) $payments->sum('amount_cents'));

        return collect(range(0, 11))->map(function (int $offset) use ($start, $totals): array {
            $month = $start->addMonths($offset);

            return ['month' => $month->format('Y-m'), 'amount_cents' => $totals->get($month->format('Y-m'), 0)];
        })->all();
    }

    /** @return list<array{country_code: string, clinics: int, users: int, revenue_cents: int}> */
    private function countrySummary(): array
    {
        $countries = Clinic::query()->distinct()->orderBy('country_code')->pluck('country_code');

        return $countries->map(function (string $country): array {
            $clinicIds = Clinic::query()->where('country_code', $country)->pluck('id');

            return [
                'country_code' => $country,
                'clinics' => Clinic::query()->where('country_code', $country)->where('status', ClinicStatus::Active->value)->count(),
                'users' => User::query()->where('country_code', $country)->where('status', UserStatus::Active->value)->count(),
                'revenue_cents' => SubscriptionPayment::query()->where('status', 'succeeded')->whereHas('subscription', fn (Builder $query) => $query->whereIn('clinic_id', $clinicIds))->sum('amount_cents'),
            ];
        })->values()->all();
    }
}
