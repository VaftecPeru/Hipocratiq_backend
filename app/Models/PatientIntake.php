<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'clinic_id',
    'patient_profile_id',
    'registered_by_user_id',
    'requested_doctor_user_id',
    'specialty_id',
    'subspecialty_id',
    'reason_category',
    'objective',
    'observations',
    'temperature_c',
    'systolic_bp',
    'diastolic_bp',
    'heart_rate',
    'respiratory_rate',
    'oxygen_saturation',
    'triage_priority',
    'triage_level',
    'triage_status',
    'submitted_at',
    'triage_notes',
])]
class PatientIntake extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'temperature_c' => 'float',
            'submitted_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function patientProfile(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class);
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    public function requestedDoctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_doctor_user_id');
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function subspecialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class, 'subspecialty_id');
    }
}
