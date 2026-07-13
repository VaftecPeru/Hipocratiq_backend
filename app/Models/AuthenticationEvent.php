<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthenticationEvent extends Model
{
    protected $fillable = [
        'user_id',
        'event',
        'outcome',
        'ip_address',
        'user_agent',
        'request_id',
        'metadata',
        'occurred_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
