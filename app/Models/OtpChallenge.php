<?php

namespace App\Models;

use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use App\Enums\OtpDeliveryStatus;
use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpChallenge extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'flow_id',
        'user_id',
        'purpose',
        'client_type',
        'actor_context',
        'device_name',
        'device_id_hash',
        'code_hash',
        'attempts',
        'max_attempts',
        'resend_count',
        'delivery_status',
        'delivery_sequence',
        'sent_at',
        'expires_at',
        'consumed_at',
        'invalidated_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'client_type' => AuthenticationClient::class,
            'actor_context' => AuthenticationContext::class,
            'resend_count' => 'integer',
            'delivery_status' => OtpDeliveryStatus::class,
            'delivery_sequence' => 'integer',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }
}
