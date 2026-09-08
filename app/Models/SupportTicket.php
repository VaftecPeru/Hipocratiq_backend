<?php

namespace App\Models;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use Database\Factories\SupportTicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'ticket_id',
    'clinic_id',
    'requested_by_user_id',
    'assigned_to_user_id',
    'category',
    'priority',
    'status',
    'subject',
    'description',
    'contact_email',
    'notify_on_create',
    'due_at',
    'first_responded_at',
    'resolved_at',
    'closed_at',
    'last_activity_at',
])]
class SupportTicket extends Model
{
    /** @use HasFactory<SupportTicketFactory> */
    use HasFactory;

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(SupportTicketActivity::class)->latest('created_at');
    }

    protected function casts(): array
    {
        return [
            'category' => SupportTicketCategory::class,
            'priority' => SupportTicketPriority::class,
            'status' => SupportTicketStatus::class,
            'notify_on_create' => 'boolean',
            'due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }
}
