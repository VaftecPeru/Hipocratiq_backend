<?php

namespace Tests\Unit\Auth;

use App\Services\Auth\OtpHasher;
use RuntimeException;
use Tests\TestCase;

class OtpHasherTest extends TestCase
{
    public function test_it_creates_deterministic_sha256_hmac(): void
    {
        config()->set('authentication.otp.hash_key', 'unit-test-key');
        $hasher = new OtpHasher;

        $first = $hasher->make('challenge-id', '123456');
        $second = $hasher->make('challenge-id', '123456');

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first));
    }

    public function test_it_rejects_wrong_code_or_challenge(): void
    {
        config()->set('authentication.otp.hash_key', 'unit-test-key');
        $hasher = new OtpHasher;
        $hash = $hasher->make('challenge-id', '123456');

        $this->assertFalse($hasher->verify('challenge-id', '654321', $hash));
        $this->assertFalse($hasher->verify('different-challenge', '123456', $hash));
    }

    public function test_it_requires_a_hash_key(): void
    {
        config()->set('authentication.otp.hash_key');
        $this->expectException(RuntimeException::class);

        (new OtpHasher)->make('challenge-id', '123456');
    }
}
