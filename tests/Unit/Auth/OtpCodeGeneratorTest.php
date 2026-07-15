<?php

namespace Tests\Unit\Auth;

use App\Services\Auth\FixedOtpCodeGenerator;
use App\Services\Auth\RandomOtpCodeGenerator;
use PHPUnit\Framework\TestCase;

class OtpCodeGeneratorTest extends TestCase
{
    public function test_random_generator_always_returns_six_digits(): void
    {
        $generator = new RandomOtpCodeGenerator;

        for ($iteration = 0; $iteration < 100; $iteration++) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', $generator->generate());
        }
    }

    public function test_fixed_generator_returns_configured_code(): void
    {
        $generator = new FixedOtpCodeGenerator('123456');

        $this->assertSame('123456', $generator->generate());
    }
}
