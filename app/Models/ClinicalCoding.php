<?php

namespace App\Models;

use App\Enums\ClinicalCodingCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'consultation_id',
    'patient_profile_id',
    'recorded_by_user_id',
    'category',
    'system',
    'code',
    'display',
    'status',
    'metadata',
    'recorded_at',
])]
class ClinicalCoding extends Model
{
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function patientProfile(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'category' => ClinicalCodingCategory::class,
            'metadata' => 'array',
            'recorded_at' => 'datetime',
        ];
    }
}
