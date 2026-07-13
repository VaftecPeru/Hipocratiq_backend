<?php

namespace App\Services\Auth;

final class FixedOtpCodeGenerator implements OtpCodeGenerator
{
    public function __construct(private string $code) {}

    public function generate(): string
    {
        return $this->code;
    }
}
