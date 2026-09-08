<?php

namespace App\Models;

use Database\Factories\ClinicalNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'clinic_id',
    'user_id',
    'type',
    'event_key',
    'source_type',
    'source_id',
    'title_key',
    'message_key',
    'severity',
    'href',
    'payload',
    'occurred_at',
    'read_at',
])]
class ClinicalNotification extends Model
{
    /** @use HasFactory<ClinicalNotificationFactory> */
    use HasFactory;

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }
}
