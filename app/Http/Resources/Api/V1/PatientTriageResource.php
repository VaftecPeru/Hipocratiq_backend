<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PatientIntake;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PatientIntake
 */
class PatientTriageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['clinic', 'registrar']);

        return [
            'id' => $this->id,
            'reason' => $this->objective,
            'temperature_c' => $this->temperature_c,
            'systolic_bp' => $this->systolic_bp,
            'diastolic_bp' => $this->diastolic_bp,
            'heart_rate' => $this->heart_rate,
            'respiratory_rate' => $this->respiratory_rate,
            'oxygen_saturation' => $this->oxygen_saturation,
            'triage_priority' => $this->triage_priority,
            'triage_notes' => $this->triage_notes,
            'recorded_at' => $this->created_at?->toIso8601String(),
            'clinic' => $this->clinic?->only(['id', 'name']),
            'recorded_by' => $this->registrar?->only(['id', 'name']),
        ];
    }
}
