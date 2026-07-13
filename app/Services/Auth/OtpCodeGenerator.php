<?php

namespace App\Services\Auth;

interface OtpCodeGenerator
{
    public function generate(): string;
}
