<?php

namespace App\Contracts;

use App\Services\Auth\PhoneOtpDelivery;

interface PhoneOtpProvider
{
    public function key(): string;

    public function issue(string $phoneNumber, string $referenceId): PhoneOtpDelivery;

    public function verify(
        string $phoneNumber,
        string $referenceId,
        string $code,
        ?string $codeHash = null,
    ): bool;
}
