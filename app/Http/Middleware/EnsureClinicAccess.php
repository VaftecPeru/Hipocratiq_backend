<?php

namespace App\Http\Middleware;

use App\Enums\ClinicMembershipStatus;
use App\Enums\ClinicStatus;
use App\Enums\RoleKey;
use App\Enums\SubscriptionStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClinicAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $membership = $request->user()->clinicMemberships()
            ->where('status', ClinicMembershipStatus::Active)
            ->whereHas('role', fn ($query) => $query->where('key', RoleKey::ClinicAdmin->value))
            ->whereHas('clinic', fn ($query) => $query->where('status', ClinicStatus::Active))
            ->with(['clinic.subscriptions' => fn ($query) => $query->where('status', SubscriptionStatus::Active->value)->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })->with('plan')->latest('starts_at')])
            ->first();

        if ($membership === null || $membership->clinic->subscriptions->isEmpty()) {
            return response()->json([
                'success' => false,
                'code' => 'CLINIC_ACCESS_REQUIRED',
                'message' => 'Tu cuenta no tiene una membresía clínica activa.',
            ], 403);
        }

        $request->attributes->set('clinic', $membership->clinic);
        $request->attributes->set('clinic_membership', $membership);

        return $next($request);
    }
}
