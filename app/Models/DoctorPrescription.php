<?php

namespace App\Models;

use App\Enums\PrescriptionStatus;
use Database\Factories\DoctorPrescriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'clinic_id',
    'doctor_user_id',
    'patient_profile_id',
    'consultation_id',
    'medication',
    'dosage',
    'frequency',
    'duration_days',
    'status',
    'issued_at',
    'expires_at',
    'notes',
])]
class DoctorPrescription extends Model
{
    /** @use HasFactory<DoctorPrescriptionFactory> */
    use HasFactory;

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
            'status' => PrescriptionStatus::class,
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'duration_days' => 'integer',
        ];
    }
}
