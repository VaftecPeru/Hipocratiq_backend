<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LaboratoryOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LaboratoryOrder
 */
class PatientLaboratoryResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['clinic', 'doctor.doctorProfile', 'consultation']);
        $resultAvailable = in_array($this->status->value, ['ready', 'reviewed'], true);

        return [
            'id' => $this->id,
            'study_name' => $this->study_name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'priority' => $this->priority,
            'ordered_at' => $this->ordered_at?->toIso8601String(),
            'result_at' => $this->result_at?->toIso8601String(),
            'result_available' => $resultAvailable,
            'result_summary' => $resultAvailable ? $this->result_summary : null,
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
