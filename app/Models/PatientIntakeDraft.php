<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['patient_profile_id', 'clinic_id', 'sections', 'declaration_accepted_at'])]
class PatientIntakeDraft extends Model
{
    use HasFactory;

    public function patientProfile(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'declaration_accepted_at' => 'datetime',
        ];
    }
}
