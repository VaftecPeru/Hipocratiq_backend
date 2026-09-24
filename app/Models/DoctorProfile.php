<?php

namespace App\Models;

use App\Enums\DoctorPracticeType;
use Database\Factories\DoctorProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'clinic_id', 'medical_license', 'medical_license_country_code', 'medical_license_issuer', 'medical_license_verification_status', 'medical_license_verified_at', 'medical_license_verification_reference', 'specialty', 'specialty_id', 'subspecialty_id', 'specialty_license', 'specialty_license_verification_status', 'specialty_license_verified_at', 'specialty_license_verification_reference', 'practice_type', 'preferences'])]
class DoctorProfile extends Model
{
    /** @use HasFactory<DoctorProfileFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(DoctorSubscription::class);
    }

    public function credentialVerifications(): HasMany
    {
        return $this->hasMany(DoctorCredentialVerification::class);
    }

    public function specialtyCatalog(): BelongsTo
    {
        return $this->belongsTo(Specialty::class, 'specialty_id');
    }

    public function subspecialtyCatalog(): BelongsTo
    {
        return $this->belongsTo(Specialty::class, 'subspecialty_id');
    }

    protected function casts(): array
    {
        return [
            'practice_type' => DoctorPracticeType::class,
            'preferences' => 'array',
            'medical_license_verified_at' => 'datetime',
            'specialty_license_verified_at' => 'datetime',
        ];
    }
}
