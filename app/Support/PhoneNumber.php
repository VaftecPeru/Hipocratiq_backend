<?php

namespace App\Support;

use InvalidArgumentException;

final readonly class PhoneNumber
{
    private function __construct(public string $value, public string $countryCode) {}

    public static function from(string $phoneNumber, string $countryCode): self
    {
        $countryCode = strtoupper($countryCode);

        if (preg_match('/[A-Za-z]/', $phoneNumber) === 1) {
            throw new InvalidArgumentException('El número de teléfono no es válido para el país seleccionado.');
        }

        $digits = preg_replace('/\D+/', '', $phoneNumber) ?? '';

        $normalized = match ($countryCode) {
            'MX' => self::normalizeMexico($digits),
            'US' => self::normalizeUnitedStates($digits),
            default => null,
        };

        if ($normalized === null) {
            throw new InvalidArgumentException('El número de teléfono no es válido para el país seleccionado.');
        }

        return new self($normalized, $countryCode);
    }

    private static function normalizeMexico(string $digits): ?string
    {
        return match (true) {
            preg_match('/^[2-9]\d{9}$/', $digits) === 1 => '+52'.$digits,
            preg_match('/^52[2-9]\d{9}$/', $digits) === 1 => '+'.$digits,
            default => null,
        };
    }

    private static function normalizeUnitedStates(string $digits): ?string
    {
        return match (true) {
            preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $digits) === 1 => '+1'.$digits,
            preg_match('/^1[2-9]\d{2}[2-9]\d{6}$/', $digits) === 1 => '+'.$digits,
            default => null,
        };
    }
}
