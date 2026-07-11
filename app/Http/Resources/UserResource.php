<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
        ];
    }
}
