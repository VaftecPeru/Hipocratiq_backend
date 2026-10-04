<?php

namespace App\Http\Middleware;

use App\Enums\AuthenticationContext;
use App\Enums\ClinicMembershipStatus;
use App\Enums\ClinicStatus;
use App\Enums\RoleKey;
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

        $activeSubscription = fn ($query) => $query->currentlyActive();

        $memberships = $request->user()->clinicMemberships()
            ->where('status', ClinicMembershipStatus::Active)
            ->whereHas('role', fn ($query) => $query->where('key', $roleKey->value))
            ->whereHas('clinic', fn ($query) => $query->where('status', ClinicStatus::Active))
            ->whereHas('clinic.subscriptions', $activeSubscription)
            ->with(['clinic.subscriptions' => fn ($query) => $activeSubscription($query)->with('plan')->latest('starts_at')])
            ->orderBy('clinic_id')
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($memberships->count() > 1) {
            return response()->json([
                'success' => false,
                'code' => 'CLINIC_CONTEXT_REQUIRED',
                'message' => 'No se pudo determinar una única clínica activa. Contacta al administrador para revisar tus membresías.',
            ], 403);
        }

        $membership = $memberships->first();

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
