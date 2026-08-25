<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PatientProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'clinic_id',
        'document_type',
        'document_number',
        'date_of_birth',
        'sex',
        'address',
        'emergency_contact',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function intakes(): HasMany
    {
        return $this->hasMany(PatientIntake::class);
    }

    public function latestIntake(): HasOne
    {
        return $this->hasOne(PatientIntake::class)->latestOfMany();
    }

    public function clinicalHistoryRecords(): HasMany
    {
        return $this->hasMany(ClinicalHistoryRecord::class);
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    protected function casts(): array
    {
        return ['date_of_birth' => 'date'];
    }
}
