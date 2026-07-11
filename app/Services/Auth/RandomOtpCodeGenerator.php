<?php

namespace App\Services\Auth;

final class RandomOtpCodeGenerator implements OtpCodeGenerator
{
    public function generate(): string
    {
        return (string) random_int(100000, 999999);
    }
}
