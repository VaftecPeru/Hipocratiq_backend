<?php

namespace App\Models;

use App\Enums\TeleconsultationStatus;
use Database\Factories\TeleconsultationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'clinic_id',
    'doctor_user_id',
    'patient_profile_id',
    'appointment_id',
    'consultation_id',
    'status',
    'scheduled_at',
    'started_at',
    'ended_at',
    'notes',
])]
class Teleconsultation extends Model
{
    /** @use HasFactory<TeleconsultationFactory> */
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

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    protected function casts(): array
    {
        return [
            'status' => TeleconsultationStatus::class,
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
