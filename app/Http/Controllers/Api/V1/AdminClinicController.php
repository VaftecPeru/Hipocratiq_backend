<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use Illuminate\Http\JsonResponse;

class AdminClinicController extends Controller
{
    public function index(): JsonResponse
    {
        $clinics = Clinic::query()
            ->withCount('memberships')
            ->with(['subscriptions' => fn ($query) => $query->with(['plan', 'payments' => fn ($query) => $query->latest('paid_at')])->latest('starts_at')])
            ->latest()
            ->get()
            ->map(function (Clinic $clinic): array {
                $subscription = $clinic->subscriptions->first();
                $payment = $subscription?->payments->first();

                return [
                    'id' => $clinic->id,
                    'name' => $clinic->name,
                    'country_code' => $clinic->country_code,
                    'address' => $clinic->address,
                    'plan' => $subscription?->plan?->key?->value ?? 'basic',
                    'users' => $clinic->memberships_count,
                    'patients' => 0,
                    'status' => $clinic->status->value,
                    'monthly_billing' => ($subscription?->plan?->monthly_price_cents ?? 0) / 100,
                    'last_payment' => $payment?->paid_at?->toDateString(),
                ];
            });

        return response()->json(['success' => true, 'data' => $clinics]);
    }
}
