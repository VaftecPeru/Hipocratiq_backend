<?php

namespace Tests\Unit\Auth;

use App\Support\PhoneNumber;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public static function supportedNumbers(): array
    {
        return [
            'peru local' => ['900112844', 'PE', '+51900112844'],
            'peru e164' => ['+51900112844', 'PE', '+51900112844'],
            'mexico local' => ['55 1234 5678', 'MX', '+525512345678'],
            'united states e164' => ['+14155552671', 'US', '+14155552671'],
        ];
    }

    #[DataProvider('supportedNumbers')]
    public function test_it_normalizes_supported_phone_numbers(
        string $input,
        string $countryCode,
        string $expected,
    ): void {
        $phoneNumber = PhoneNumber::from($input, $countryCode);

        $this->assertSame($expected, $phoneNumber->value);
        $this->assertSame($countryCode, $phoneNumber->countryCode);
    }

    public function test_it_detects_country_from_e164_number(): void
    {
        $phoneNumber = PhoneNumber::fromE164('+51900112844');

        $this->assertSame('+51900112844', $phoneNumber->value);
        $this->assertSame('PE', $phoneNumber->countryCode);
    }

    public function test_it_rejects_invalid_e164_number(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PhoneNumber::fromE164('900112844');
    }

    public function test_it_rejects_letters_in_phone_number(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PhoneNumber::from('900ABC844', 'PE');
    }
}
