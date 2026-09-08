<?php

namespace App\Models;

use App\Enums\SystemIncidentSeverity;
use App\Enums\SystemIncidentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'title',
    'description',
    'severity',
    'status',
    'service_key',
    'country_code',
    'created_by_user_id',
    'resolved_by_user_id',
    'started_at',
    'resolved_at',
])]
class SystemIncident extends Model
{
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'severity' => SystemIncidentSeverity::class,
            'status' => SystemIncidentStatus::class,
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
