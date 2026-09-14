<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\HasCurrentlyActiveSubscriptionScope;
use Database\Factories\DoctorSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['doctor_profile_id', 'plan_id', 'billing_cycle', 'status', 'starts_at', 'ends_at', 'provider', 'provider_reference'])]
class DoctorSubscription extends Model
{
    /** @use HasFactory<DoctorSubscriptionFactory> */
    use HasCurrentlyActiveSubscriptionScope, HasFactory;

    public function doctorProfile(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
