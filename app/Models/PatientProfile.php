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
        'owner_doctor_user_id',
        'document_type',
        'document_number',
        'date_of_birth',
        'sex',
        'address',
        'emergency_contact',
        'insurance',
        'is_returning_patient',
        'whatsapp_consent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function ownerDoctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_doctor_user_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function intakes(): HasMany
    {
        return $this->hasMany(PatientIntake::class);
    }

    public function intakeDrafts(): HasMany
    {
        return $this->hasMany(PatientIntakeDraft::class);
    }

    public function latestIntake(): HasOne
    {
        return $this->hasOne(PatientIntake::class)->latestOfMany();
    }

    public function lastAppointment(): HasOne
    {
        return $this->appointments()->one()->ofMany(
            ['scheduled_at' => 'max', 'id' => 'max'],
            fn ($query) => $query->where('scheduled_at', '<=', now()),
        );
    }

    public function nextAppointment(): HasOne
    {
        return $this->appointments()->one()->ofMany(
            ['scheduled_at' => 'min', 'id' => 'min'],
            fn ($query) => $query->where('scheduled_at', '>=', now()),
        );
    }

    public function clinicalHistoryRecords(): HasMany
    {
        return $this->hasMany(ClinicalHistoryRecord::class);
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(PatientConsent::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(DoctorPrescription::class);
    }

    public function laboratoryOrders(): HasMany
    {
        return $this->hasMany(LaboratoryOrder::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(ClinicInvoice::class);
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_returning_patient' => 'boolean',
            'whatsapp_consent' => 'boolean',
        ];
    }
}
