<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'parent_id', 'is_active'])]
class Specialty extends Model
{
    use HasFactory;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function subspecialties(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function doctorProfiles(): HasMany
    {
        return $this->hasMany(DoctorProfile::class, 'specialty_id');
    }

    public function doctorSubspecialtyProfiles(): HasMany
    {
        return $this->hasMany(DoctorProfile::class, 'subspecialty_id');
    }

    public function patientIntakes(): HasMany
    {
        return $this->hasMany(PatientIntake::class, 'specialty_id');
    }

    public function patientSubspecialtyIntakes(): HasMany
    {
        return $this->hasMany(PatientIntake::class, 'subspecialty_id');
    }
}
