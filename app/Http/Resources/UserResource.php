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
        $clinicMembership = $this->clinicMemberships()
            ->where('status', 'active')
            ->with(['clinic.subscriptions' => fn ($query) => $query->where('status', 'active')->with('plan')->latest('starts_at')])
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
                'patient' => $this->patientProfile()->exists(),
            ],
            'roles' => $this->roles->pluck('key')->all(),
            'is_global' => $this->hasRole(RoleKey::SuperAdmin),
            'clinic' => $clinicMembership === null ? null : [
                'id' => $clinicMembership->clinic->id,
                'name' => $clinicMembership->clinic->name,
                'status' => $clinicMembership->clinic->status,
                'plan' => $clinicMembership->clinic->subscriptions->first()?->plan?->key,
            ],
        ];
    }
}
