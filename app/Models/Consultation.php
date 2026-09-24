<?php

namespace App\Models;

use App\Enums\ConsultationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'clinic_id',
    'doctor_user_id',
    'patient_profile_id',
    'appointment_id',
    'clinical_history_record_id',
    'created_by_user_id',
    'status',
    'chief_complaint',
    'objective',
    'signed_by_user_id',
    'signed_doctor_name',
    'signed_doctor_medical_license',
    'signed_doctor_license_country_code',
    'signed_doctor_license_issuer',
    'signed_doctor_specialty_license',
    'signed_doctor_specialty',
    'signed_at',
    'signature_digest',
    'completed_at',
])]
class Consultation extends Model
{
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

    public function clinicalHistoryRecord(): BelongsTo
    {
        return $this->belongsTo(ClinicalHistoryRecord::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'status' => ConsultationStatus::class,
            'signed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
