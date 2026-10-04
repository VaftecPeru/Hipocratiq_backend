<?php

namespace App\Models;

use App\Enums\PatientConsentScope;
use App\Enums\PatientConsentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'patient_profile_id',
    'clinic_id',
    'granted_by_user_id',
    'revoked_by_user_id',
    'scope',
    'policy_version',
    'status',
    'source',
    'effective_from',
    'effective_until',
    'revoked_at',
    'metadata',
])]
class PatientConsent extends Model
{
    public function patientProfile(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'scope' => PatientConsentScope::class,
            'status' => PatientConsentStatus::class,
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
