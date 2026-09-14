<?php

namespace App\Http\Resources;

use App\Enums\AuthenticationContext;
use App\Enums\ClinicMembershipStatus;
use App\Enums\ClinicStatus;
use App\Enums\DoctorPracticeType;
use App\Enums\RoleKey;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\DoctorProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('roles');
        $patientProfile = $this->patientProfile()->with('clinic')->first();
        $doctorProfile = $this->doctorProfile()
            ->with(['clinic', 'specialtyCatalog', 'subspecialtyCatalog', 'subscriptions' => fn (HasMany $query): HasMany => $query->currentlyActive()->with('plan')->latest('starts_at')])
            ->first();
        $actorContext = $this->actorContext($request);
        $clinicMembership = $this->eligibleClinicMembership($request, $actorContext, $doctorProfile);
        $doctorClinic = $actorContext === AuthenticationContext::Doctor
            ? $clinicMembership?->clinic
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'country_code' => $this->country_code,
            'phone_verified_at' => $this->phone_verified_at,
            'status' => $this->status,
            'onboarding_status' => $this->onboarding_status,
            'profiles' => [
                'patient' => $patientProfile !== null,
                'doctor' => $doctorProfile !== null,
            ],
            'roles' => $this->roles->pluck('key')->all(),
            'is_global' => $this->hasRole(RoleKey::SuperAdmin),
            'patient' => $patientProfile === null ? null : [
                'clinic' => $patientProfile->clinic?->only(['id', 'name']),
                'plan' => null,
            ],
            'clinic' => $clinicMembership === null ? null : [
                'id' => $clinicMembership->clinic->id,
                'name' => $clinicMembership->clinic->name,
                'status' => $clinicMembership->clinic->status,
                'plan' => $clinicMembership->clinic->subscriptions->first()?->plan?->key,
            ],
            'doctor' => $doctorProfile === null ? null : [
                'medical_license' => $doctorProfile->medical_license,
                'specialty' => $doctorProfile->specialty,
                'specialty_id' => $doctorProfile->specialty_id,
                'subspecialty_id' => $doctorProfile->subspecialty_id,
                'specialty_catalog' => $doctorProfile->specialtyCatalog?->only(['id', 'code', 'name']),
                'subspecialty_catalog' => $doctorProfile->subspecialtyCatalog?->only(['id', 'code', 'name']),
                'practice_type' => $doctorProfile->practice_type,
                'clinic' => $doctorClinic?->only(['id', 'name']),
                'plan' => $doctorProfile->subscriptions->first()?->plan?->key,
            ],
        ];
    }

    private function actorContext(Request $request): ?AuthenticationContext
    {
        $value = $request->attributes->get('actor_context');

        if ($value === null && $request->hasSession()) {
            $value = $request->session()->get('actor_context');
        }

        if ($value instanceof AuthenticationContext) {
            return $value;
        }

        return is_string($value) ? AuthenticationContext::tryFrom($value) : null;
    }

    private function eligibleClinicMembership(
        Request $request,
        ?AuthenticationContext $actorContext,
        ?DoctorProfile $doctorProfile,
    ): ?ClinicMembership {
        $roleKey = match ($actorContext) {
            AuthenticationContext::ClinicAdmin => RoleKey::ClinicAdmin,
            AuthenticationContext::Receptionist => RoleKey::Receptionist,
            AuthenticationContext::Doctor => RoleKey::Doctor,
            default => null,
        };

        if ($roleKey === null) {
            return null;
        }

        if ($actorContext === AuthenticationContext::Doctor
            && ($doctorProfile === null
                || $doctorProfile->practice_type !== DoctorPracticeType::Clinic
                || $doctorProfile->clinic_id === null)) {
            return null;
        }

        $query = $this->clinicMemberships()
            ->where('status', ClinicMembershipStatus::Active->value)
            ->whereHas('role', fn (Builder $query): Builder => $query->where('key', $roleKey->value))
            ->whereHas('clinic', fn (Builder $query): Builder => $query->where('status', ClinicStatus::Active->value))
            ->whereHas('clinic.subscriptions', fn (Builder $query): Builder => $query->currentlyActive())
            ->with([
                'clinic',
                'clinic.subscriptions' => fn (HasMany $query): HasMany => $query->currentlyActive()->with('plan')->latest('starts_at'),
            ]);

        if ($actorContext === AuthenticationContext::Doctor) {
            $query->where('clinic_id', $doctorProfile->clinic_id);
        }

        $requestClinic = $request->attributes->get('clinic');
        if ($requestClinic instanceof Clinic) {
            $query->where('clinic_id', $requestClinic->getKey());
        }

        $memberships = $query
            ->orderBy('clinic_id')
            ->orderBy('id')
            ->get();

        return $memberships->count() === 1 ? $memberships->first() : null;
    }
}
