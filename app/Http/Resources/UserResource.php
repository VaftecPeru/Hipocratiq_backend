<?php

namespace App\Http\Resources;

use App\Enums\RoleKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('roles');
        $patientProfile = $this->patientProfile()->with('clinic')->first();
        $clinicMembership = $this->clinicMemberships()
            ->where('status', 'active')
            ->with(['clinic.subscriptions' => fn ($query) => $query->where('status', 'active')->with('plan')->latest('starts_at')])
            ->first();
        $doctorProfile = $this->doctorProfile()
            ->with(['clinic', 'subscriptions' => fn ($query) => $query->where('status', 'active')->with('plan')->latest('starts_at')])
            ->first();

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
                'practice_type' => $doctorProfile->practice_type,
                'clinic' => $doctorProfile->clinic?->only(['id', 'name']),
                'plan' => $doctorProfile->subscriptions->first()?->plan?->key,
            ],
        ];
    }
}
