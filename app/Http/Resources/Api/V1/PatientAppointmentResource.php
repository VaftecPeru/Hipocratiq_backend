<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class PatientAppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['clinic', 'doctor.doctorProfile']);

        return [
            'id' => $this->id,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'appointment_type' => $this->appointment_type,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'notes' => $this->notes,
            'clinic' => $this->clinic?->only(['id', 'name']),
            'doctor' => $this->doctor === null ? null : [
                'id' => $this->doctor->id,
                'name' => $this->doctor->name,
                'specialty' => $this->doctor->doctorProfile?->specialty,
                'medical_license' => $this->doctor->doctorProfile?->medical_license,
            ],
        ];
    }
}
