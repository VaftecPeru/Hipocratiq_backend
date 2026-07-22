<?php

namespace App\Models;

use App\Enums\DoctorPracticeType;
use Database\Factories\DoctorProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'clinic_id', 'medical_license', 'specialty', 'practice_type'])]
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

    protected function casts(): array
    {
        return ['practice_type' => DoctorPracticeType::class];
    }
}
