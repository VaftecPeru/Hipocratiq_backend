<?php

namespace App\Models;

use Database\Factories\SubscriptionPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['clinic_subscription_id', 'idempotency_key', 'amount_cents', 'currency', 'status', 'provider', 'provider_reference', 'reconciliation_note', 'refund_reference', 'paid_at', 'refunded_at'])]
class SubscriptionPayment extends Model
{
    /** @use HasFactory<SubscriptionPaymentFactory> */
    use HasFactory;

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ClinicSubscription::class, 'clinic_subscription_id');
    }

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'paid_at' => 'datetime', 'refunded_at' => 'datetime'];
    }
}
