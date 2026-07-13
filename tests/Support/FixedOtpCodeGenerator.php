<?php

namespace Tests\Support;

use App\Services\Auth\OtpCodeGenerator;

final class FixedOtpCodeGenerator implements OtpCodeGenerator
{
    public function __construct(private string $code = '123456') {}

    public function generate(): string
    {
        return $this->code;
    }
}
