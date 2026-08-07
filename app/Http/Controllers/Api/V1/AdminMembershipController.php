<?php

namespace App\Http\Controllers\Api\V1;

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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AdminMembershipController extends Controller
{
    public function index(): JsonResponse
    {
        $activeSubscriptions = $this->activeSubscriptionQuery()
            ->with(['clinic', 'plan'])
            ->get();

        $mrrCents = (int) $activeSubscriptions
            ->sum(fn (ClinicSubscription $subscription): int => $this->monthlyAmountCents($subscription));

        return response()->json([
            'success' => true,
            'data' => [
                'kpis' => [
                    'mrr' => [
                        'usd_cents' => $mrrCents,
                        'pen_cents' => $this->penCents($mrrCents),
                        'change_pct' => $this->paymentChangePct(30),
                        'change_note' => 'vs. mes anterior',
                    ],
                    'arr' => [
                        'usd_cents' => $mrrCents * 12,
                        'pen_cents' => $this->penCents($mrrCents * 12),
                        'change_pct' => $this->paymentChangePct(365),
                        'change_note' => 'vs. año anterior',
                    ],
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
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function storePlan(MembershipPlanStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $plan = Plan::create([
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'monthly_price_cents' => $this->dollarsToCents($data['monthly_price_usd']),
            'annual_price_cents' => $this->dollarsToCents($data['annual_price_usd']),
            'currency' => config('memberships.currency'),
            'features' => $data['features'],
            'publicly_selectable' => $data['publicly_selectable'] ?? true,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializePlan($plan),
        ], 201);
    }

    public function updatePlan(Plan $plan, MembershipPlanUpdateRequest $request): JsonResponse
    {
        $data = $request->validated();
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
            'features' => $data['features'] ?? $plan->features,
            'publicly_selectable' => $data['publicly_selectable'] ?? $plan->publicly_selectable,
            'is_active' => $data['is_active'] ?? $plan->is_active,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializePlan($plan),
        ]);
    }

    public function storeSubscription(MembershipSubscriptionCreateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $startsAt = Carbon::parse($data['starts_at'])->startOfDay();

        $subscription = ClinicSubscription::create([
            'clinic_id' => $data['clinic_id'],
            'plan_id' => $data['plan_id'],
            'billing_cycle' => $data['billing_cycle'],
            'status' => SubscriptionStatus::Active->value,
            'auto_renew' => $data['auto_renew'] ?? true,
            'starts_at' => $startsAt,
            'ends_at' => $this->cycleEndDate($startsAt, $data['billing_cycle']),
            'provider' => 'simulated',
            'provider_reference' => Str::uuid()->toString(),
        ]);
        $subscription->load(['clinic', 'plan']);

        return response()->json([
            'success' => true,
            'data' => $this->serializeSubscription($subscription),
        ], 201);
    }

    public function upgradeSubscription(ClinicSubscription $subscription, MembershipUpgradeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $upgradeAt = isset($data['upgrade_at'])
            ? Carbon::parse($data['upgrade_at'])->startOfDay()
            : now()->startOfDay();

        $subscription->update([
            'plan_id' => $data['plan_id'],
            'starts_at' => $upgradeAt,
            'ends_at' => $this->cycleEndDate($upgradeAt, $subscription->billing_cycle->value),
        ]);
        $subscription->load(['clinic', 'plan']);

        return response()->json([
            'success' => true,
            'data' => $this->serializeSubscription($subscription),
        ]);
    }

    public function cancelSubscription(ClinicSubscription $subscription): JsonResponse
    {
        $subscription->update([
            'status' => SubscriptionStatus::Canceled->value,
            'auto_renew' => false,
        ]);

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
        return ClinicSubscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            });
    }

    private function conversionRate(): float
    {
        return (float) config('memberships.exchange_rate_usd_to_pen');
    }

    private function penCents(int $usdCents): int
    {
        return (int) round($usdCents * $this->conversionRate());
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

    private function paymentChangePct(int $days): ?float
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
            ->sum('amount_cents');
        $previous = SubscriptionPayment::query()
            ->where('status', 'succeeded')
            ->whereBetween('paid_at', $previousWindow)
            ->sum('amount_cents');

        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
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
            'monthly_usd_cents' => $monthlyCents,
            'monthly_pen_cents' => $this->penCents($monthlyCents),
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
                $amountUsdCents = $this->monthlyAmountCents($subscription);

                return [
                    'id' => $subscription->id,
                    'clinic_id' => $subscription->clinic_id,
                    'clinic' => $subscription->clinic?->name,
                    'country_code' => $subscription->clinic?->country_code,
                    'plan' => $subscription->plan?->key ?? PlanKey::Basic->value,
                    'amount_usd_cents' => $amountUsdCents,
                    'amount_pen_cents' => $this->penCents($amountUsdCents),
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
