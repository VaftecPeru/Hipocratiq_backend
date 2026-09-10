<?php

namespace App\Models;

use Database\Factories\ClinicInvoiceItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['clinic_invoice_id', 'description', 'quantity', 'unit_amount_minor', 'line_total_minor'])]
class ClinicInvoiceItem extends Model
{
    /** @use HasFactory<ClinicInvoiceItemFactory> */
    use HasFactory;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClinicInvoice::class, 'clinic_invoice_id');
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }
}
