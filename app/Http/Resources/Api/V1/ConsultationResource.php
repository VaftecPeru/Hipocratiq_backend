<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Consultation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Consultation
 */
class ConsultationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['clinic', 'doctor', 'patientProfile.user', 'appointment', 'clinicalHistoryRecord.entries.section', 'signedBy']);

        $record = $this->clinicalHistoryRecord;

        return [
            'id' => $this->id,
            'clinic' => $this->clinic?->only(['id', 'name']),
            'doctor' => $this->doctor?->only(['id', 'name']),
            'patient' => $this->patientProfile === null ? null : [
                'id' => $this->patientProfile->id,
                'name' => $this->patientProfile->user?->name,
                'sex' => $this->patientProfile->sex,
                'date_of_birth' => $this->patientProfile->date_of_birth?->format('Y-m-d'),
            ],
            'appointment_id' => $this->appointment_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'chief_complaint' => $this->chief_complaint,
            'objective' => $this->objective,
            'signature' => [
                'signed_by' => $this->signedBy?->only(['id', 'name']),
                'signed_at' => $this->signed_at?->toIso8601String(),
            ],
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'clinical_history' => $record === null ? null : [
                'id' => $record->id,
                'source' => $record->source,
                'objective' => $record->objective,
                'sections' => $record->entries->mapWithKeys(fn ($entry): array => [
                    $entry->section?->key => $entry->values,
                ])->all(),
            ],
        ];
    }
}
