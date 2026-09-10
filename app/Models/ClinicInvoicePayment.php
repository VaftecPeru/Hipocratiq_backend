<?php

namespace App\Models;

use App\Enums\ClinicInvoicePaymentStatus;
use Database\Factories\ClinicInvoicePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['clinic_invoice_id', 'amount_minor', 'currency', 'status', 'payment_method', 'reference', 'note', 'paid_at', 'recorded_by_user_id'])]
class ClinicInvoicePayment extends Model
{
    /** @use HasFactory<ClinicInvoicePaymentFactory> */
    use HasFactory;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClinicInvoice::class, 'clinic_invoice_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => ClinicInvoicePaymentStatus::class,
            'paid_at' => 'datetime',
        ];
    }
}
