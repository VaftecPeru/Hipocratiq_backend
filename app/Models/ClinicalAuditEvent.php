<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'actor_user_id',
    'clinic_id',
    'patient_profile_id',
    'consultation_id',
    'action',
    'ip_address',
    'user_agent',
    'request_id',
    'before_payload',
    'after_payload',
    'occurred_at',
])]
class ClinicalAuditEvent extends Model
{
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function patientProfile(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Los eventos de auditoría clínica son inmutables.');
        });

        static::deleting(function (): void {
            throw new LogicException('Los eventos de auditoría clínica no se pueden eliminar.');
        });
    }

    protected function casts(): array
    {
        return [
            'before_payload' => 'encrypted:array',
            'after_payload' => 'encrypted:array',
            'occurred_at' => 'datetime',
        ];
    }
}
