<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BillingCycle;
use App\Enums\ClinicStatus;
use App\Enums\PlanKey;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminClinicIndexRequest;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\PatientProfile;
use App\Models\SubscriptionPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class AdminClinicController extends Controller
{
    public function index(AdminClinicIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $clinics = Clinic::query()
            ->withCount(['memberships', 'patientProfiles'])
            ->with(['latestSubscription.plan', 'latestSubscription.latestSuccessfulPayment'])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%");
                });
            })
            ->when($filters['country_code'] ?? null, fn ($query, string $countryCode) => $query->where('country_code', $countryCode))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['city'] ?? null, fn ($query, string $city) => $query->where('address', 'like', "%{$city}%"))
            ->when($filters['plan'] ?? null, function ($query, string $plan): void {
                $query->whereHas(
                    'latestSubscription.plan',
                    fn ($query) => $query->where('key', $plan),
                );
            })
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 10);

        $data = $clinics->getCollection()
            ->map(fn (Clinic $clinic): array => $this->serializeClinic($clinic));

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $clinics->currentPage(),
                'from' => $clinics->firstItem(),
                'last_page' => $clinics->lastPage(),
                'per_page' => $clinics->perPage(),
                'to' => $clinics->lastItem(),
                'total' => $clinics->total(),
            ],
            'summary' => $this->summary(),
        ]);
    }

    /**
     * @return array<string, int|float|string|null>
     */
    private function serializeClinic(Clinic $clinic): array
    {
        $subscription = $clinic->latestSubscription;
        $plan = $subscription?->plan;
        $monthlyBillingCents = match ($subscription?->billing_cycle) {
            BillingCycle::Annual => (int) round(($plan?->annual_price_cents ?? 0) / 12),
            default => $plan?->monthly_price_cents ?? 0,
        };

        return [
            'id' => $clinic->id,
            'name' => $clinic->name,
            'country_code' => $clinic->country_code,
            'address' => $clinic->address,
            'plan' => $plan?->key?->value ?? PlanKey::Basic->value,
            'users' => $clinic->memberships_count,
            'patients' => $clinic->patient_profiles_count,
            'status' => $clinic->status->value,
            'monthly_billing' => round($monthlyBillingCents / 100, 2),
            'last_payment' => $subscription?->latestSuccessfulPayment?->paid_at?->toDateString(),
        ];
    }

    /**
     * @return array{
     *     totals: array<string, int|float>,
     *     plan_distribution: list<array{plan: string, count: int, percentage: float}>,
     *     recent_activity: list<array{id: int, type: string, title: string, detail: string, occurred_at: string}>
     * }
     */
    private function summary(): array
    {
        $clinicStatusCounts = Clinic::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $totalClinics = (int) $clinicStatusCounts->sum();
        $payments = SubscriptionPayment::query()
            ->where('status', 'succeeded')
            ->whereNotNull('paid_at');
        $monthlyRevenueCents = (clone $payments)
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount_cents');
        $annualRevenueCents = (clone $payments)
            ->whereBetween('paid_at', [now()->startOfYear(), now()->endOfYear()])
            ->sum('amount_cents');

        return [
            'totals' => [
                'clinics' => $totalClinics,
                'active_clinics' => (int) ($clinicStatusCounts[ClinicStatus::Active->value] ?? 0),
                'suspended_clinics' => (int) ($clinicStatusCounts[ClinicStatus::Suspended->value] ?? 0),
                'users' => ClinicMembership::query()->count(),
                'patients' => PatientProfile::query()->whereNotNull('clinic_id')->count(),
                'monthly_revenue' => round($monthlyRevenueCents / 100, 2),
                'annual_revenue' => round($annualRevenueCents / 100, 2),
            ],
            'plan_distribution' => $this->planDistribution(),
            'recent_activity' => Clinic::query()
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'name', 'country_code', 'created_at'])
                ->map(fn (Clinic $clinic): array => [
                    'id' => $clinic->id,
                    'type' => 'clinic',
                    'title' => 'Nueva clínica',
                    'detail' => "{$clinic->name} · {$clinic->country_code}",
                    'occurred_at' => $clinic->created_at->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array{plan: string, count: int, percentage: float}>
     */
    private function planDistribution(): array
    {
        $latestSubscriptionIds = ClinicSubscription::query()
            ->selectRaw('MAX(id)')
            ->groupBy('clinic_id');
        $counts = ClinicSubscription::query()
            ->join('plans', 'plans.id', '=', 'clinic_subscriptions.plan_id')
            ->whereIn('clinic_subscriptions.id', $latestSubscriptionIds)
            ->where('clinic_subscriptions.status', SubscriptionStatus::Active->value)
            ->where(function ($query): void {
                $query
                    ->whereNull('clinic_subscriptions.ends_at')
                    ->orWhere('clinic_subscriptions.ends_at', '>', now());
            })
            ->selectRaw('plans.key, COUNT(*) as aggregate')
            ->groupBy('plans.key')
            ->pluck('aggregate', 'plans.key');
        $totalSubscriptions = (int) $counts->sum();

        return Collection::make(PlanKey::cases())
            ->map(function (PlanKey $plan) use ($counts, $totalSubscriptions): array {
                $count = (int) ($counts[$plan->value] ?? 0);

                return [
                    'plan' => $plan->value,
                    'count' => $count,
                    'percentage' => $totalSubscriptions === 0
                        ? 0
                        : round(($count / $totalSubscriptions) * 100, 1),
                ];
            })
            ->values()
            ->all();
    }
}
