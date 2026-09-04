<?php

namespace App\Models;

use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $hidden = [
        'token',
    ];

    protected $fillable = [
        'name',
        'token',
        'abilities',
        'client_type',
        'actor_context',
        'device_name',
        'device_id_hash',
        'expires_at',
    ];

    protected $casts = [
        'abilities' => 'json',
        'client_type' => AuthenticationClient::class,
        'actor_context' => AuthenticationContext::class,
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
