<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ClinicalHistoryRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClinicalHistoryRecord
 */
class ClinicalHistoryRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['entries.section', 'clinic', 'doctor']);

        return [
            'id' => $this->id,
            'clinic' => $this->clinic?->only(['id', 'name']),
            'patient_profile_id' => $this->patient_profile_id,
            'doctor' => $this->doctor?->only(['id', 'name']),
            'appointment_id' => $this->appointment_id,
            'source' => $this->source,
            'objective' => $this->objective,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'sections' => $this->entries->map(fn ($entry): array => [
                'section_key' => $entry->section?->key,
                'section_title' => $entry->section?->title,
                'values' => $entry->values,
            ])->values(),
        ];
    }
}
