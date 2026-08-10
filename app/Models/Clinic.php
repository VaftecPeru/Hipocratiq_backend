<?php

namespace App\Models;

use App\Enums\ClinicStatus;
use Database\Factories\ClinicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'slug', 'tax_id', 'country_code', 'contact_phone', 'address', 'status'])]
class Clinic extends Model
{
    /** @use HasFactory<ClinicFactory> */
    use HasFactory;

    public function memberships(): HasMany
    {
        return $this->hasMany(ClinicMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'clinic_memberships')
            ->withPivot(['role_id', 'status'])
            ->withTimestamps();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ClinicSubscription::class);
    }

    public function latestSubscription(): HasOne
    {
        return $this->hasOne(ClinicSubscription::class)->latestOfMany('starts_at');
    }

    public function doctorProfiles(): HasMany
    {
        return $this->hasMany(DoctorProfile::class);
    }

    public function patientProfiles(): HasMany
    {
        return $this->hasMany(PatientProfile::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    protected function casts(): array
    {
        return ['status' => ClinicStatus::class];
    }
}
