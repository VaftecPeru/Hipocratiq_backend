<?php

namespace App\Models;

use App\Enums\DoctorPracticeType;
use App\Enums\OnboardingStatus;
use App\Enums\RoleKey;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'email',
    'email_verified_at',
    'phone_number',
    'country_code',
    'password',
    'status',
    'onboarding_status',
    'phone_verified_at',
    'last_login_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function patientProfile(): HasOne
    {
        return $this->hasOne(PatientProfile::class);
    }

    public function doctorProfile(): HasOne
    {
        return $this->hasOne(DoctorProfile::class);
    }

    public function otpChallenges(): HasMany
    {
        return $this->hasMany(OtpChallenge::class);
    }

    public function authenticationSessions(): HasMany
    {
        return $this->hasMany(AuthenticationSession::class);
    }

    public function requestedSupportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'requested_by_user_id');
    }

    public function assignedSupportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'assigned_to_user_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function clinicMemberships(): HasMany
    {
        return $this->hasMany(ClinicMembership::class);
    }

    public function doctorAppointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'doctor_user_id');
    }

    public function ownedPatients(): HasMany
    {
        return $this->hasMany(PatientProfile::class, 'owner_doctor_user_id');
    }

    public function registeredPatientIntakes(): HasMany
    {
        return $this->hasMany(PatientIntake::class, 'registered_by_user_id');
    }

    public function requestedPatientIntakes(): HasMany
    {
        return $this->hasMany(PatientIntake::class, 'requested_doctor_user_id');
    }

    public function doctorConsultations(): HasMany
    {
        return $this->hasMany(Consultation::class, 'doctor_user_id');
    }

    public function doctorClinicalHistoryRecords(): HasMany
    {
        return $this->hasMany(ClinicalHistoryRecord::class, 'doctor_user_id');
    }

    public function hasActiveClinicMembership(): bool
    {
        return $this->clinicMemberships()->where('status', 'active')->exists();
    }

    public function hasActiveClinicAdminMembership(): bool
    {
        return $this->clinicMemberships()
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->where('key', RoleKey::ClinicAdmin->value))
            ->whereHas('clinic', fn ($query) => $query->where('status', 'active'))
            ->whereHas('clinic.subscriptions', fn ($query) => $query->currentlyActive())
            ->exists();
    }

    public function hasActiveReceptionistAccess(): bool
    {
        return $this->clinicMemberships()
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->where('key', RoleKey::Receptionist->value))
            ->whereHas('clinic', fn ($query) => $query->where('status', 'active'))
            ->whereHas('clinic.subscriptions', fn ($query) => $query->currentlyActive())
            ->exists();
    }

    public function hasActiveDoctorAccess(): bool
    {
        $profile = $this->doctorProfile()->first();

        if ($profile === null || ! $this->hasRole(RoleKey::Doctor)) {
            return false;
        }

        if ($profile->practice_type === DoctorPracticeType::Clinic) {
            return $this->clinicMemberships()
                ->where('clinic_id', $profile->clinic_id)
                ->where('status', 'active')
                ->whereHas('clinic', fn ($query) => $query->where('status', 'active'))
                ->whereHas('clinic.subscriptions', fn ($query) => $query->currentlyActive())
                ->exists();
        }

        return $profile->subscriptions()
            ->currentlyActive()
            ->exists();
    }

    public function hasRole(RoleKey|string $role): bool
    {
        $key = $role instanceof RoleKey ? $role->value : $role;
        $this->loadMissing('roles');

        return $this->roles->contains('key', $key);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'status' => UserStatus::class,
            'onboarding_status' => OnboardingStatus::class,
            'password' => 'hashed',
        ];
    }
}
