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
            'PE' => self::normalizePeru($digits),
            'MX' => self::normalizeMexico($digits),
            'US' => self::normalizeUnitedStates($digits),
            default => null,
        };

        if ($normalized === null) {
            throw new InvalidArgumentException('El número de teléfono no es válido para el país seleccionado.');
        }

        return new self($normalized, $countryCode);
    }

    public static function fromE164(string $phoneNumber): self
    {
        if (preg_match('/^\+[1-9]\d{7,14}$/', $phoneNumber) !== 1) {
            throw new InvalidArgumentException('El número de teléfono debe usar el formato internacional E.164.');
        }

        return match (true) {
            str_starts_with($phoneNumber, '+51') => self::from($phoneNumber, 'PE'),
            str_starts_with($phoneNumber, '+52') => self::from($phoneNumber, 'MX'),
            str_starts_with($phoneNumber, '+1') => self::from($phoneNumber, 'US'),
            default => throw new InvalidArgumentException('El país del número de teléfono no está disponible.'),
        };
    }

    private static function normalizePeru(string $digits): ?string
    {
        return match (true) {
            preg_match('/^9\d{8}$/', $digits) === 1 => '+51'.$digits,
            preg_match('/^519\d{8}$/', $digits) === 1 => '+'.$digits,
            default => null,
        };
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
