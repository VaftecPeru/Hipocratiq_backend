<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DoctorPrescription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DoctorPrescription
 */
class PatientPrescriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['clinic', 'doctor.doctorProfile', 'consultation']);

        return [
            'id' => $this->id,
            'medication' => $this->medication,
            'dosage' => $this->dosage,
            'frequency' => $this->frequency,
            'duration_days' => $this->duration_days,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'notes' => $this->notes,
            'consultation_id' => $this->consultation_id,
            'doctor' => $this->doctor === null ? null : [
                'id' => $this->doctor->id,
                'name' => $this->doctor->name,
                'specialty' => $this->doctor->doctorProfile?->specialty,
            ],
            'clinic' => $this->clinic?->only(['id', 'name']),
        ];
    }
}
