<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'actor_user_id',
    'clinic_id',
    'country_code',
    'module',
    'action',
    'is_critical',
    'resource_type',
    'resource_id',
    'resource_label',
    'ip_address',
    'user_agent',
    'request_id',
    'metadata',
    'occurred_at',
])]
class AdminAuditEvent extends Model
{
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    protected function casts(): array
    {
        return [
            'is_critical' => 'boolean',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
