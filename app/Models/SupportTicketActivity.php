<?php

namespace App\Models;

use App\Enums\SupportTicketActivityType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'support_ticket_id',
    'actor_user_id',
    'type',
    'body',
    'from_value',
    'to_value',
    'is_internal',
    'metadata',
    'created_at',
])]
class SupportTicketActivity extends Model
{
    public const UPDATED_AT = null;

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return [
            'type' => SupportTicketActivityType::class,
            'is_internal' => 'boolean',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
