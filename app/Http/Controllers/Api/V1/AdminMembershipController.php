<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Enums\BillingCycle;
use App\Enums\PlanKey;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MembershipPlanStoreRequest;
use App\Http\Requests\Api\V1\MembershipPlanUpdateRequest;
use App\Http\Requests\Api\V1\MembershipSubscriptionCreateRequest;
use App\Http\Requests\Api\V1\MembershipUpgradeRequest;
use App\Models\ClinicSubscription;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Support\AdminDashboardCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminMembershipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = AdminDashboardCache::remember(
            AdminDashboardCache::resourceKey('memberships', $request->user()?->getKey()),
            fn (): array => $this->dashboardPayload(),
        );

        return response()->json($payload);
    }

    /** @return array<string, mixed> */
    private function dashboardPayload(): array
    {
        $activeSubscriptions = $this->activeSubscriptionQuery()
            ->with(['clinic', 'plan'])
            ->get();

        $mrrByCurrency = $this->subscriptionTotalsByCurrency($activeSubscriptions);

        return [
            'success' => true,
            'data' => [
                'kpis' => [
                    'mrr' => $this->kpiMetric($mrrByCurrency, 1, 30),
                    'arr' => $this->kpiMetric($mrrByCurrency, 12, 365),
                    'active_subscriptions' => [
                        'count' => $activeSubscriptions->count(),
                        'change_count' => $activeSubscriptions
                            ->filter(fn (ClinicSubscription $subscription): bool => $subscription->starts_at !== null
                                && $subscription->starts_at->isBetween(now()->startOfMonth(), now()->endOfMonth()))
                            ->count(),
                        'change_note' => 'nuevas este mes',
                    ],
                    'renewal_rate' => [
                        'percentage' => $this->renewalRatePercentage($activeSubscriptions),
                        'change_pct' => null,
                        'change_note' => 'renovación automática',
                    ],
                ],
                'plans' => $this->serializePlans($activeSubscriptions),
                'subscriptions_by_country' => $this->subscriptionsByCountry($activeSubscriptions),
                'subscriptions_by_plan' => $this->subscriptionsByPlan($activeSubscriptions),
                'upcoming_renewals' => $this->upcomingRenewals($activeSubscriptions),
                'conversion_rate' => $this->conversionRate(),
                'currency_rates' => $this->currencyRates(),
                'subscriptions' => ClinicSubscription::query()
                    ->with(['clinic', 'plan'])
                    ->latest('id')
                    ->limit(100)
                    ->get()
                    ->map(fn (ClinicSubscription $subscription): array => $this->serializeSubscription($subscription))
                    ->values()
                    ->all(),
                'payment_history' => SubscriptionPayment::query()
                    ->with(['subscription.clinic', 'subscription.plan'])
                    ->latest('id')
                    ->limit(100)
                    ->get()
                    ->map(fn (SubscriptionPayment $payment): array => $this->serializePayment($payment))
                    ->values()
                    ->all(),
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    public function storePlan(MembershipPlanStoreRequest $request, RecordAdminAuditEvent $audit): JsonResponse
    {
        $data = $request->validated();
        $plan = Plan::create([
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'monthly_price_cents' => $this->dollarsToCents($data['monthly_price_usd']),
            'annual_price_cents' => $this->dollarsToCents($data['annual_price_usd']),
            'currency' => strtoupper($data['currency'] ?? config('memberships.currency')),
            'features' => $data['features'],
            'publicly_selectable' => $data['publicly_selectable'] ?? true,
            'is_active' => $data['is_active'] ?? true,
        ]);
        $audit->handle(
            $request->user(),
            $request,
            'memberships',
            'plan_created',
            Plan::class,
            $plan->id,
            $plan->name,
            metadata: ['key' => $plan->key, 'monthly_price_cents' => $plan->monthly_price_cents],
            critical: true,
        );

        return response()->json([
            'success' => true,
            'data' => $this->serializePlan($plan),
        ], 201);
    }

    public function updatePlan(Plan $plan, MembershipPlanUpdateRequest $request, RecordAdminAuditEvent $audit): JsonResponse
    {
        $data = $request->validated();
        $before = $plan->only(['key', 'name', 'monthly_price_cents', 'annual_price_cents', 'currency', 'publicly_selectable', 'is_active']);
        $plan->update([
            'key' => $data['key'] ?? $plan->key,
            'name' => $data['name'] ?? $plan->name,
            'description' => array_key_exists('description', $data)
                ? $data['description']
                : $plan->description,
            'monthly_price_cents' => isset($data['monthly_price_usd'])
                ? $this->dollarsToCents($data['monthly_price_usd'])
                : $plan->monthly_price_cents,
            'annual_price_cents' => isset($data['annual_price_usd'])
                ? $this->dollarsToCents($data['annual_price_usd'])
                : $plan->annual_price_cents,
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : $plan->currency,
            'features' => $data['features'] ?? $plan->features,
            'publicly_selectable' => $data['publicly_selectable'] ?? $plan->publicly_selectable,
            'is_active' => $data['is_active'] ?? $plan->is_active,
        ]);
        $audit->handle(
            $request->user(),
            $request,
            'memberships',
            'plan_updated',
            Plan::class,
            $plan->id,
            $plan->name,
            metadata: [
                'before' => $before,
                'after' => $plan->only(array_keys($before)),
            ],
            critical: true,
        );

        return response()->json([
            'success' => true,
            'data' => $this->serializePlan($plan),
        ]);
    }

    public function storeSubscription(MembershipSubscriptionCreateRequest $request, RecordAdminAuditEvent $audit): JsonResponse
    {
        $data = $request->validated();
        $startsAt = Carbon::parse($data['starts_at'])->startOfDay();

        $subscription = DB::transaction(function () use ($data, $startsAt): ClinicSubscription {
            $hasActiveSubscription = ClinicSubscription::query()
                ->where('clinic_id', $data['clinic_id'])
                ->currentlyActive()
                ->lockForUpdate()
                ->exists();

            if ($hasActiveSubscription) {
                throw ValidationException::withMessages([
                    'clinic_id' => ['La clínica ya tiene una suscripción activa.'],
                ]);
            }

            return ClinicSubscription::create([
                'clinic_id' => $data['clinic_id'],
                'plan_id' => $data['plan_id'],
                'billing_cycle' => $data['billing_cycle'],
                'status' => SubscriptionStatus::Active->value,
                'auto_renew' => $data['auto_renew'] ?? true,
                'starts_at' => $startsAt,
                'ends_at' => $this->cycleEndDate($startsAt, $data['billing_cycle']),
                'provider' => 'admin_manual',
                'provider_reference' => 'admin-subscription-'.Str::uuid(),
            ]);
        });
        $subscription->load(['clinic', 'plan']);
        $audit->handle(
            $request->user(),
            $request,
            'memberships',
            'subscription_created',
            ClinicSubscription::class,
            $subscription->id,
            $subscription->clinic?->name,
            $subscription->clinic,
            metadata: ['plan' => $subscription->plan?->key, 'billing_cycle' => $subscription->billing_cycle->value],
            critical: true,
        );

        return response()->json([
            'success' => true,
            'data' => $this->serializeSubscription($subscription),
        ], 201);
    }

    public function upgradeSubscription(
        ClinicSubscription $subscription,
        MembershipUpgradeRequest $request,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $data = $request->validated();
        $previousPlanId = $subscription->plan_id;
        $upgradeAt = isset($data['upgrade_at'])
            ? Carbon::parse($data['upgrade_at'])->startOfDay()
            : now()->startOfDay();

        $subscription->update([
            'plan_id' => $data['plan_id'],
            'starts_at' => $upgradeAt,
            'ends_at' => $this->cycleEndDate($upgradeAt, $subscription->billing_cycle->value),
        ]);
        $subscription->load(['clinic', 'plan']);
        $audit->handle(
            $request->user(),
            $request,
            'memberships',
            'subscription_upgraded',
            ClinicSubscription::class,
            $subscription->id,
            $subscription->clinic?->name,
            $subscription->clinic,
            metadata: ['previous_plan_id' => $previousPlanId, 'new_plan_id' => $subscription->plan_id],
            critical: true,
        );

        return response()->json([
            'success' => true,
            'data' => $this->serializeSubscription($subscription),
        ]);
    }

    public function cancelSubscription(
        ClinicSubscription $subscription,
        Request $request,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $subscription->update([
            'status' => SubscriptionStatus::Canceled->value,
            'auto_renew' => false,
        ]);
        $subscription->loadMissing(['clinic', 'plan']);
        $audit->handle(
            $request->user(),
            $request,
            'memberships',
            'subscription_canceled',
            ClinicSubscription::class,
            $subscription->id,
            $subscription->clinic?->name,
            $subscription->clinic,
            metadata: ['plan' => $subscription->plan?->key],
            critical: true,
        );

        return response()->json([
            'success' => true,
            'data' => $this->serializeSubscription($subscription),
        ]);
    }

    /**
     * Suscripciones clínicas vigentes (no canceladas ni expiradas).
     */
    private function activeSubscriptionQuery(): Builder
    {
        return ClinicSubscription::query()->currentlyActive();
    }

    private function conversionRate(): ?float
    {
        $rate = config('memberships.exchange_rate_usd_to_pen');

        return $rate === null ? null : (float) $rate;
    }

    /** @return array<string, float|null> */
    private function currencyRates(): array
    {
        $configuredRates = config('memberships.exchange_rates_usd', []);

        return collect($this->supportedCurrencies())
            ->mapWithKeys(fn (string $currency): array => [
                $currency => isset($configuredRates[$currency]) ? (float) $configuredRates[$currency] : null,
            ])
            ->all();
    }

    /** @return list<string> */
    private function supportedCurrencies(): array
    {
        return config('memberships.supported_currencies', ['USD', 'MXN', 'PEN']);
    }

    private function penCents(int $usdCents): ?int
    {
        $rate = $this->conversionRate();

        return $rate === null ? null : (int) round($usdCents * $rate);
    }

    private function dollarsToCents(float|int|string $dollars): int
    {
        return (int) round(((float) $dollars) * 100);
    }

    /**
     * Monto que la suscripción factura cada mes según su ciclo.
     */
    private function monthlyAmountCents(ClinicSubscription $subscription): int
    {
        $plan = $subscription->plan;

        return match ($subscription->billing_cycle) {
            BillingCycle::Annual => (int) round(($plan?->annual_price_cents ?? 0) / 12),
            default => $plan?->monthly_price_cents ?? 0,
        };
    }

    /** @return array<string, int> */
    private function subscriptionTotalsByCurrency(Collection $subscriptions): array
    {
        $totals = array_fill_keys($this->supportedCurrencies(), 0);

        foreach ($subscriptions as $subscription) {
            $currency = $subscription->plan?->currency ?? config('memberships.currency');

            if (array_key_exists($currency, $totals)) {
                $totals[$currency] += $this->monthlyAmountCents($subscription);
            }
        }

        return $totals;
    }

    /** @param array<string, int> $amounts */
    private function kpiMetric(array $amounts, int $multiplier, int $days): array
    {
        $scaled = collect($amounts)->map(fn (int $amount): int => $amount * $multiplier)->all();
        $changes = $this->paymentChangePctByCurrency($days);

        return [
            'usd_cents' => (int) ($scaled['USD'] ?? 0),
            'pen_cents' => (int) ($scaled['PEN'] ?? 0),
            'by_currency' => $scaled,
            'change_pct' => $changes['USD'] ?? null,
            'change_pct_by_currency' => $changes,
            'change_note' => 'variación por moneda',
        ];
    }

    /**
     * Fecha de la próxima renovación (fin del periodo o fin calculado a partir del inicio).
     */
    private function renewalDate(ClinicSubscription $subscription): Carbon
    {
        if ($subscription->ends_at !== null) {
            return $subscription->ends_at;
        }

        return $this->cycleEndDate(
            $subscription->starts_at ?? now(),
            $subscription->billing_cycle->value,
        );
    }

    private function cycleEndDate(Carbon $startsAt, string $billingCycle): Carbon
    {
        return $billingCycle === BillingCycle::Annual->value
            ? $startsAt->copy()->addYear()->startOfDay()
            : $startsAt->copy()->addMonths(1)->startOfDay();
    }

    /** @return array<string, float|null> */
    private function paymentChangePctByCurrency(int $days): array
    {
        $currentWindow = $days === 365
            ? [now()->startOfYear(), now()->endOfYear()]
            : [now()->startOfMonth(), now()->endOfMonth()];
        $previousWindow = $days === 365
            ? [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()]
            : [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()];

        $current = SubscriptionPayment::query()
            ->where('status', 'succeeded')
            ->whereBetween('paid_at', $currentWindow)
            ->select('currency')
            ->selectRaw('SUM(amount_cents) as amount_cents')
            ->groupBy('currency')
            ->pluck('amount_cents', 'currency');
        $previous = SubscriptionPayment::query()
            ->where('status', 'succeeded')
            ->whereBetween('paid_at', $previousWindow)
            ->select('currency')
            ->selectRaw('SUM(amount_cents) as amount_cents')
            ->groupBy('currency')
            ->pluck('amount_cents', 'currency');

        return collect($this->supportedCurrencies())->mapWithKeys(function (string $currency) use ($current, $previous): array {
            $currentAmount = (int) ($current[$currency] ?? 0);
            $previousAmount = (int) ($previous[$currency] ?? 0);

            return [$currency => $previousAmount <= 0 ? null : round((($currentAmount - $previousAmount) / $previousAmount) * 100, 1)];
        })->all();
    }

    private function renewalRatePercentage(Collection $subscriptions): float
    {
        if ($subscriptions->isEmpty()) {
            return 0.0;
        }

        $autoRenewCount = $subscriptions->filter(
            fn (ClinicSubscription $subscription): bool => (bool) $subscription->auto_renew,
        )->count();

        return round(($autoRenewCount / $subscriptions->count()) * 100, 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializePlans(Collection $subscriptions): array
    {
        $subscriberCounts = $subscriptions
            ->groupBy(fn (ClinicSubscription $subscription): string => $subscription->plan?->key ?? PlanKey::Basic->value)
            ->map->count();

        return Plan::query()
            ->orderBy('monthly_price_cents')
            ->get()
            ->map(function (Plan $plan) use ($subscriberCounts): array {
                $serialized = $this->serializePlan($plan);
                $serialized['subscribers'] = (int) ($subscriberCounts[$plan->key] ?? 0);
                $serialized['popular'] = $plan->key === config('memberships.popular_plan')
                    && $serialized['subscribers'] > 0;

                return $serialized;
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePlan(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'key' => $plan->key,
            'name' => $plan->name,
            'description' => $plan->description,
            'monthly_price_cents' => $plan->monthly_price_cents,
            'annual_price_cents' => $plan->annual_price_cents,
            'monthly_price_usd_cents' => $plan->monthly_price_cents,
            'annual_price_usd_cents' => $plan->annual_price_cents,
            'currency' => $plan->currency,
            'features' => $plan->features,
            'publicly_selectable' => $plan->publicly_selectable,
            'is_active' => $plan->is_active,
        ];
    }

    /**
     * @return array<string, int|float|bool|string|null>
     */
    private function serializeSubscription(ClinicSubscription $subscription): array
    {
        $monthlyCents = $this->monthlyAmountCents($subscription);
        $currency = $subscription->plan?->currency ?? config('memberships.currency');

        return [
            'id' => $subscription->id,
            'clinic_id' => $subscription->clinic_id,
            'clinic' => $subscription->clinic?->name,
            'country_code' => $subscription->clinic?->country_code,
            'plan' => $subscription->plan?->key,
            'plan_name' => $subscription->plan?->name,
            'billing_cycle' => $subscription->billing_cycle->value,
            'status' => $subscription->status->value,
            'auto_renew' => $subscription->auto_renew,
            'starts_at' => $subscription->starts_at?->toIso8601String(),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
            'amount_cents' => $monthlyCents,
            'currency' => $currency,
            'monthly_usd_cents' => $currency === 'USD' ? $monthlyCents : null,
            'monthly_pen_cents' => $currency === 'USD' ? $this->penCents($monthlyCents) : ($currency === 'PEN' ? $monthlyCents : null),
        ];
    }

    /** @return array<string, mixed> */
    private function serializePayment(SubscriptionPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'reference' => $payment->provider_reference,
            'clinic' => $payment->subscription?->clinic?->name,
            'country_code' => $payment->subscription?->clinic?->country_code,
            'plan' => $payment->subscription?->plan?->name,
            'status' => $payment->status,
            'amount_cents' => $payment->amount_cents,
            'currency' => $payment->currency,
            'provider' => $payment->provider,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'refunded_at' => $payment->refunded_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{country_code: string, count: int, percentage: float}>
     */
    private function subscriptionsByCountry(Collection $subscriptions): array
    {
        $counts = $subscriptions
            ->filter(fn (ClinicSubscription $subscription): bool => $subscription->clinic?->country_code !== null)
            ->groupBy(fn (ClinicSubscription $subscription): string => $subscription->clinic->country_code)
            ->map->count();
        $total = (int) $counts->sum();

        return $counts
            ->map(fn (int $count, string $countryCode): array => [
                'country_code' => $countryCode,
                'count' => $count,
                'percentage' => $total === 0 ? 0 : round(($count / $total) * 100, 1),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @return list<array{plan: string, count: int, percentage: float}>
     */
    private function subscriptionsByPlan(Collection $subscriptions): array
    {
        $counts = $subscriptions
            ->groupBy(fn (ClinicSubscription $subscription): string => $subscription->plan?->key ?? PlanKey::Basic->value)
            ->map->count();
        $total = (int) $counts->sum();

        return Plan::query()
            ->orderBy('monthly_price_cents')
            ->get()
            ->map(function (Plan $plan) use ($counts, $total): array {
                $count = (int) ($counts[$plan->key] ?? 0);

                return [
                    'plan' => $plan->key,
                    'count' => $count,
                    'percentage' => $total === 0 ? 0 : round(($count / $total) * 100, 1),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upcomingRenewals(Collection $subscriptions): array
    {
        $windowStart = now()->startOfDay();
        $windowEnd = now()->addDays((int) config('memberships.renewal_window_days'))->endOfDay();

        return $subscriptions
            ->map(function (ClinicSubscription $subscription): array {
                $renewalAt = $this->renewalDate($subscription);
                $amountCents = $this->monthlyAmountCents($subscription);
                $currency = $subscription->plan?->currency ?? config('memberships.currency');

                return [
                    'id' => $subscription->id,
                    'clinic_id' => $subscription->clinic_id,
                    'clinic' => $subscription->clinic?->name,
                    'country_code' => $subscription->clinic?->country_code,
                    'plan' => $subscription->plan?->key ?? PlanKey::Basic->value,
                    'amount_cents' => $amountCents,
                    'currency' => $currency,
                    'amount_usd_cents' => $currency === 'USD' ? $amountCents : null,
                    'amount_pen_cents' => $currency === 'USD' ? $this->penCents($amountCents) : ($currency === 'PEN' ? $amountCents : null),
                    'renewal_at' => $renewalAt->toDateString(),
                    'auto_renew' => $subscription->auto_renew,
                ];
            })
            ->filter(fn (array $renewal): bool => $renewal['renewal_at'] >= $windowStart->toDateString()
                && $renewal['renewal_at'] <= $windowEnd->toDateString())
            ->sortBy('renewal_at')
            ->take(20)
            ->values()
            ->all();
    }
}
