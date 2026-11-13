<?php

namespace App\Services\Auth;

final readonly class PhoneOtpDelivery
{
    public function __construct(
        public string $provider,
        public ?string $codeHash = null,
        public ?string $messageId = null,
        public ?string $verificationId = null,
    ) {}
}
