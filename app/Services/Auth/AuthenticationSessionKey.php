<?php

namespace App\Services\Auth;

final class AuthenticationSessionKey
{
    public function make(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, (string) config('app.key'));
    }
}
