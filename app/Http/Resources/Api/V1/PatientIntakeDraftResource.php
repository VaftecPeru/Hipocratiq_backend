<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PatientIntakeDraft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PatientIntakeDraft
 */
class PatientIntakeDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('clinic');

        return [
            'id' => $this->id,
            'status' => 'draft',
            'sections' => $this->sections ?? [],
            'declaration_accepted_at' => $this->declaration_accepted_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'clinic' => $this->clinic?->only(['id', 'name']),
        ];
    }

    public function with(Request $request): array
    {
        return [
            'meta' => [
                'patient_owned' => true,
                'clinician_only_sections' => [
                    'physical_examination',
                    'labs_imaging',
                    'diagnoses_problems',
                    'pharmacological_treatment',
                    'prognosis',
                    'clinician_identification',
                ],
            ],
        ];
    }
}
