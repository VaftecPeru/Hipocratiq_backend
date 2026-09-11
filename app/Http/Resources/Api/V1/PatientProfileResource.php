<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PatientProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PatientProfile
 */
class PatientProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['user', 'clinic']);

        return [
            'id' => $this->id,
            'patient_code' => 'PAC-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT),
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            'phone_number' => $this->user?->phone_number,
            'country_code' => $this->user?->country_code,
            'status' => $this->user?->status?->value,
            'document_type' => $this->document_type,
            'document_number' => $this->document_number,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'sex' => $this->sex,
            'address' => $this->address,
            'emergency_contact' => $this->emergency_contact,
            'insurance' => $this->insurance,
            'whatsapp_consent' => (bool) $this->whatsapp_consent,
            'created_at' => $this->user?->created_at?->toIso8601String(),
            'clinic' => $this->clinic?->only(['id', 'name']),
        ];
    }
}
