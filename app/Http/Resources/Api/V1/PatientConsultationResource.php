<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Consultation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Consultation
 */
class PatientConsultationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'clinic',
            'doctor.doctorProfile',
            'appointment',
            'clinicalHistoryRecord.entries.section',
        ]);
        $record = $this->clinicalHistoryRecord;

        return [
            'id' => $this->id,
            'appointment_id' => $this->appointment_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'chief_complaint' => $this->chief_complaint,
            'objective' => $this->objective,
            'doctor' => $this->doctor === null ? null : [
                'id' => $this->doctor->id,
                'name' => $this->doctor->name,
                'specialty' => $this->doctor->doctorProfile?->specialty,
            ],
            'clinic' => $this->clinic?->only(['id', 'name']),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'signed_at' => $this->signed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'clinical_history' => $record === null ? null : [
                'id' => $record->id,
                'source' => $record->source,
                'objective' => $record->objective,
                'sections' => $record->entries
                    ->filter(fn ($entry): bool => $entry->section !== null)
                    ->mapWithKeys(fn ($entry): array => [$entry->section->key => $entry->values])
                    ->all(),
            ],
        ];
    }
}
