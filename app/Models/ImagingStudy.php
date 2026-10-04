<?php

namespace App\Models;

use App\Enums\ImagingStudyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'clinic_id',
    'doctor_user_id',
    'patient_profile_id',
    'consultation_id',
    'accession_number',
    'study_instance_uid',
    'modality',
    'study_description',
    'status',
    'pacs_provider',
    'pacs_study_key',
    'report_summary',
    'notes',
    'ordered_at',
    'study_at',
    'available_at',
    'reviewed_at',
])]
class ImagingStudy extends Model
{
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_user_id');
    }

    public function patientProfile(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ImagingStudyStatus::class,
            'ordered_at' => 'datetime',
            'study_at' => 'datetime',
            'available_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
