<?php

namespace App\Models;

use App\Enums\ClinicInvoiceStatus;
use Database\Factories\ClinicInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['clinic_id', 'patient_profile_id', 'invoice_number', 'issue_date', 'due_date', 'total_minor', 'currency', 'status'])]
class ClinicInvoice extends Model
{
    /** @use HasFactory<ClinicInvoiceFactory> */
    use HasFactory;

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function patientProfile(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClinicInvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ClinicInvoicePayment::class);
    }

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'total_minor' => 'integer',
            'status' => ClinicInvoiceStatus::class,
        ];
    }
}
