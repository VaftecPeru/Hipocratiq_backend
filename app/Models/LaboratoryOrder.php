<?php

namespace App\Models;

use App\Enums\LaboratoryOrderStatus;
use Database\Factories\LaboratoryOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'clinic_id',
    'doctor_user_id',
    'patient_profile_id',
    'consultation_id',
    'study_name',
    'status',
    'priority',
    'is_critical',
    'ordered_at',
    'result_at',
    'result_summary',
    'notes',
])]
class LaboratoryOrder extends Model
{
    /** @use HasFactory<LaboratoryOrderFactory> */
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
            'status' => LaboratoryOrderStatus::class,
            'is_critical' => 'boolean',
            'ordered_at' => 'datetime',
            'result_at' => 'datetime',
        ];
    }
}
