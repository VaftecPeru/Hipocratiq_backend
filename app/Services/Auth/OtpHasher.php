<?php

namespace App\Services\Auth;

use RuntimeException;

final class OtpHasher
{
    public function make(string $challengeId, string $code): string
    {
        return hash_hmac('sha256', $challengeId.':'.$code, $this->key());
    }

    public function verify(string $challengeId, string $code, string $hash): bool
    {
        return hash_equals($hash, $this->make($challengeId, $code));
    }

    private function key(): string
    {
        $key = config('authentication.otp.hash_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('OTP_HASH_KEY no está configurado.');
        }

        return $key;
    }
}
