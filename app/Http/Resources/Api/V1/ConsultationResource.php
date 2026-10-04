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
        $this->resource->loadMissing(['clinic', 'doctor', 'patientProfile.user', 'appointment', 'clinicalHistoryRecord.entries.section', 'codings', 'signedBy']);

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
            'codings' => $this->codings->map(fn ($coding): array => [
                'category' => $coding->category->value,
                'system' => $coding->system,
                'code' => $coding->code,
                'display' => $coding->display,
                'status' => $coding->status,
            ])->values()->all(),
            'signature' => [
                'signed_by' => $this->signedBy?->only(['id', 'name']),
                'signed_at' => $this->signed_at?->toIso8601String(),
                'doctor_name' => $this->signed_doctor_name,
                'medical_license' => $this->signed_doctor_medical_license,
                'license_country_code' => $this->signed_doctor_license_country_code,
                'license_issuer' => $this->signed_doctor_license_issuer,
                'specialty_license' => $this->signed_doctor_specialty_license,
                'specialty' => $this->signed_doctor_specialty,
                'digest' => $this->signature_digest,
                'integrity_sha256' => $this->signature_digest,
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
