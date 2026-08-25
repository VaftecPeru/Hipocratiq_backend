<?php

namespace App\Http\Middleware;

use App\Enums\AuthenticationContext;
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
        $actorContext = $request->attributes->get('actor_context');
        $roleKey = is_string($actorContext) ? match (AuthenticationContext::tryFrom($actorContext)) {
            AuthenticationContext::ClinicAdmin => RoleKey::ClinicAdmin,
            AuthenticationContext::Receptionist => RoleKey::Receptionist,
            default => null,
        } : null;

        if ($roleKey === null) {
            return $this->accessRequiredResponse();
        }

        $membership = $request->user()->clinicMemberships()
            ->where('status', ClinicMembershipStatus::Active)
            ->whereHas('role', fn ($query) => $query->where('key', $roleKey->value))
            ->whereHas('clinic', fn ($query) => $query->where('status', ClinicStatus::Active))
            ->whereHas('clinic.subscriptions', fn ($query) => $query->where('status', SubscriptionStatus::Active->value)->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            }))
            ->with(['clinic.subscriptions' => fn ($query) => $query->where('status', SubscriptionStatus::Active->value)->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })->with('plan')->latest('starts_at')])
            ->orderBy('clinic_id')
            ->orderBy('id')
            ->first();

        if ($membership === null || $membership->clinic->subscriptions->isEmpty()) {
            return $this->accessRequiredResponse();
        }

        $request->attributes->set('clinic', $membership->clinic);
        $request->attributes->set('clinic_membership', $membership);

        return $next($request);
    }

    private function accessRequiredResponse(): Response
    {
        return response()->json([
            'success' => false,
            'code' => 'CLINIC_ACCESS_REQUIRED',
            'message' => 'Tu cuenta no tiene una membresía clínica activa.',
        ], 403);
    }
}
