<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PatientProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PatientProfile
 */
class DoctorPatientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('user', 'clinic');

        return [
            'id' => $this->id,
            'name' => $this->user?->name,
            'document_type' => $this->document_type,
            'document_number' => $this->document_number,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'sex' => $this->sex,
            'address' => $this->address,
            'clinic' => $this->clinic?->only(['id', 'name']),
        ];
    }
}
